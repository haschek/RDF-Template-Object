<?php

include_once(dirname(__FILE__).'/rdfto.interface.php');

/**
 * ARC2 Template Object
 *
 * implements the RDF Template Object and Helper methods, using ARC2 library
 * to parse RDF files. ARC2 lib must be included before using this class.
 *
 * @see http://arc.semsol.org/
 *
 * @version 0.1
 * @author Michael Haschke, http://eye48.com/
 * @license GNU General Public License 2, http://www.gnu.org/licenses/gpl-2.0.html
 **/
class ARC2File_Template_Object implements RDF_Template_Object, RDF_Template_Helper
{

    public $resource = null; // arc2 resource object

    public $uri; // contains the resource URI which is wrapped by the object */
    public $ns_prefix = 'foaf'; // contains namespace prefix of model which defines the type of the currently wrapped object  */

    public $level = 0; // linked data level (how many steps of objects which has been requested for other objects)
    public $levelMax = 1; // maximum level for linked data

    public $requests = 0; // count linked data requests
    public $requestsMax = 50; // maximum linked data requests
    public $requestsUris = array(); // contains all requested URIs, must be additionally saved because we cannot check the array for unsuccesful requests and sameAs data
    public $requestsTimeout = 3;

    public $ignoreUris = array();

    public $templateImage = 'SET TEMPLATE FOR IMAGE';
    

    /* Constructor for Template Object
     *
     * TODO
     * @since 0.1
     */
    public function __construct(Array $environment)
    {
        $this->levelMax = $environment['levelMax'];
        $this->requestsMax = $environment['requestsMax'];
        
        return;
    }
    
    /* TODO
     * @since 0.1
     */
    public function initResource($arc2_resource)
    {
        $this->resource = $arc2_resource;
        $this->uri = $arc2_resource->uri;
        $this->updateNamespacePrefix();
        $this->addLogMessage('init: count '.count($this->resource->index).' -- memory '.intval(memory_get_usage(true)/1024).'kb');
        $this->includeSameAs($this->uri);
        
        return;
    }
    
    /* Magic method __GET
     *
     * Magically get vars from the used RDF parser library or transform
     * requests for predicates of the wrapped resource. Returns arrays of the
     * objects.
     *
     * @since 0.1
     *
     * @parameter $name name of ARC2 resource variable or predicate
     *
     * @return Array result array with simple types or ARC2File_Template_Object
     */
    public function __get($name)
    {
        // test for native arc2 resource vars
        if (isset($this->resource->$name))
            return $this->resource->$name;
        
        // or form to predicates
        
        $getLinkedData = true;
        
        // check for prefix '_nld_' which implies not to request linked data
        if (substr($name, 0, 5) == '_nld_')
        {
            $getLinkedData = false;
            $name = substr($name, 5);
        }
        
        $predicate = explode('_', $name);
        if (count($predicate) == 1)
        {
            $predicate = $this->ns_prefix.':'.$name;
        }
        elseif (count($predicate) == 2)
        {
            $predicate = implode(':', $predicate);
        }
        else
        {
            $predicate = $predicate[0].':'.implode('_', array_slice($predicate, 1));
        }
        
        $objects = $this->resource->getProps($predicate, $this->uri);

        // return empty object array
        if (count($objects)==0) return array();
        
        foreach ($objects as $index => $data)
        {
            if ($data['type']=='uri' || $data['type']=='bnode')
            {
                // return wrapped arc2 resource for type=uri|bnode
                if (isset($this->resource->index[$data['value']]))
                {
                    $objects[$index] = clone $this;
                    $objects[$index]->uri = $data['value']; // object
                }
                // except for resources without further indexed data
                else
                {
                    $objects[$index] = $data['value']; // must be an uri
                    if ($getLinkedData)
                        $objects[$index] = $this->getLinkedData($data['value']);
                }
            }
            else
            {
                // return the value straight (without type)
                // for literals and other data types
                $objects[$index] = $data['value']; // data|literal
                
                // add another value for language
                if (isset($data['lang']))
                    $objects[$data['lang']][] = $data['value']; // data|literal
            }
        }
        
        return $objects;
    
    }
    
    /* Magic method __CALL
     * 
     * Magically call methods from the used RDF parser library or transform
     * requests for predicates filtered by object types of the wrapped resource.
     * Returns arrays of the objects.
     *
     * @since 0.1
     *
     * @param string $name name of Arc2 method or predicate
     * @param Array $types arguments for Arc2 method or rdf:type filter (use a
     *                     minus '-' as prefix to exclude instances of rdf:type,
     *                     e.g. '-vcard:Home'), last item of the array could be
     *                     a boolean TRUE if the results should be interesected
     *
     * @return Array result array with simple types or ARC2File_Template_Object
     */
    public function __call($name, $types)
    {
        // check for existing methods in Arc2 resource object
        // TODO
        
        // emulate predicate requests filtered by rdf:type
        
        // no filters attached
        if (count($types) == 0) return $this->$name;
        
        // get results without filtering
        $references = $this->$name;
        
        // check intersect argument
        if ($types[(count($types)-1)] === true)
        {
            $types = array_slice($types, 0, -1);
            $intersect = true;
        }
        else
        {
            $intersect = false;
        }
        
        // part add/sub filters
        $filters_add = array();
        $filters_sub = array();
        
        foreach($types as $type)
        {
            if (substr($type, 0, 1) == '-')
            {
                $type = substr($type, 1);
                $filters_sub[] = ($s = strpos($type, ':')) ? $this->resource->ns[(substr($type, 0, $s))].substr($type, $s+1) : $type;
            }
            else
            {
                $filters_add[] = ($s = strpos($type, ':')) ? $this->resource->ns[(substr($type, 0, $s))].substr($type, $s+1) : $type;
            }
        }
        
        // only use results with informations about rdf:type
        
        $references_types = array();
        
        foreach ($references as $id => $ref)
        {
            if (!is_object($ref) ||
                !isset($this->resource->index[$ref->uri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type']))
            {
                unset($references[$id]);
            }
            else
            {
                foreach ($this->resource->index[$ref->uri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $reftype)
                {
                    $references_types[$ref->uri][] = $reftype['value'];
                }
            }
        }
        
        reset($references);
        
        // use substractive filters to delete items from results
        # $this->resource->index[$this->uri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'][0]['value']
        
        foreach ($references as $id => $ref)
        {
            foreach ($filters_sub as $type)
            {
                if (array_search($type, $references_types[$ref->uri]) !== false)
                {
                    unset($references[$id]);
                    break;
                }
            }
        }
        
        reset($references);
        
        // use additive filters to keep result items
        
        foreach ($references as $id => $ref)
        {
            $count_types = 0;
            
            foreach ($filters_add as $type)
            {
                if (array_search($type, $references_types[$ref->uri]) !== false)
                {
                    $count_types++;
                }
            }
            
            if ($count_types == 0 || ($intersect && $count_types != count($filters_add)))
            {
                unset($references[$id]);
            }
        }
        
        return $references;
        
    }
    
    /* TODO
     * @since 0.1
     */
    public function __toString()
    {
        $index = array();
        if (isset($this->resource->index[$this->uri]))
            $index[$this->uri] = $this->resource->index[$this->uri];
        return serialize($index);
    }
    
    /* TODO
     * @since 0.1
     */
    public function getLinkedData($uri, $alias = null)
    {
        // $testuri = ''; // may defined by sub class for debug checks
        
        $this->addLogMessage('  --> request data: '.$uri.' ('.$alias.')');

        $indexedData = null;
        
        // must be an uri b/c blanknode without data makes no sense
        // but better to check
        if (substr($uri, 0 , 1) != '_'
            && array_search($uri, $this->ignoreUris) === false
            && array_search($uri, $this->requestsUris) === false)
        {
        
            $this->requestsUris[] = $uri;
        
            // test cache for indexed data of uri
            if (false === ($indexedData = $this->getCache(array('name'=>$uri, 'space'=>'FoafpressData'))))
            {
                // no data cached - request data from uri
                
                // use uri without local anchors (they should be included)
                $uriAbs = $uri;
                if (strpos($uri, '#') !== false)
                    $uriAbs = substr($uri, 0, strpos($uri, '#'));
                    
                // try to get linked data, only if max LinkedData level
                // and requests not reached

                $linkedData = null;
            
                // check cache for data
                if (false === ($linkedData = $this->getCache(array('name'=>$uriAbs, 'space'=>'FoafpressDataAbs'))))
                {
                    // if (is_array($linkedData) && count($linkedData) == 0) die($uriAbs);
                
                    if ($this->level < $this->levelMax &&
                        $this->requests < $this->requestsMax)
                    {
                        // parse feed
                        $dataParser = ARC2::getRDFParser(array('reader_timeout'=>3, 'keep_time_limit'=>true));
                        /*
                        if (!isset($dataParser->reader)) {
                          ARC2::inc('Reader');
                          $dataParser->reader = & new ARC2_Reader($dataParser->a, $dataParser);
                        }
                        $dataParser->reader->timeout = $this->requestsTimeout;
                        //*/
                        //$dataParser->parse($uriAbs, null, 0, $this->requestsTimeout);
                        $dataParser->parse($uriAbs, null);
                        //print_r(array($uriAbs=>$dataParser->reader->getResponseHeaders()));
                        $this->requests++;
                        if (is_object($dataParser))
                        {
                            $linkedData = $dataParser->getSimpleIndex(0);
                            // save cache
                            $this->addLogMessage('  --> save to cache: '.$uriAbs);
                            $this->saveCache(array('data'=>$linkedData, 'name'=>$uriAbs, 'space'=>'FoafpressDataAbs', 'time'=>true));
                        }
                        unset($dataParser);
                    }

                    $this->addLogMessage('  --> read: '.$uriAbs.' ('.count($linkedData).' elements)');
                    
                }
                else
                {
                    $this->addLogMessage('  --> read from cache: '.$uriAbs.' ('.count($linkedData).' elements)');
                }

                // check outdated cache as fallback
                // better old data than no data :)
                if (!$linkedData)
                {
                    if ($linkedData = $this->getCache(array('name'=>$uriAbs, 'space'=>'FoafpressDataAbs', 'time'=>-1)))
                    {
                        $this->addLogMessage('  --> read from OUTDATED cache: '.$uriAbs.' ('.count($linkedData).' elements)');
                    }
                }
                
                if (isset($testuri) && $uriAbs == $testuri) die('<pre>'.print_r($linkedData, true).'</pre>');

                if ($linkedData)
                {
                    // check linkedData, only keep uri and blanknodes which are referenced by resource
                    
                    if (isset($linkedData[$uri]))
                    {
                        //$indexedData[$uri] = $linkedData[$uri];
                        $indexedData = $linkedData;

                        if (isset($testuri) && $uri == $testuri) die('<pre>'.print_r($indexedData, true).'</pre>');
                        
                        /* TODO: reduceMemoryUsage()
                        $indexedData[$uri] = $linkedData[$uri];
                        if ($this->environment['FP_config']['LinkedData']['useBnodes'] === true)
                        {
                            // keep bnodes in results
                            $bnodes = $this->listBnodes($indexedData[$uri]);
                            foreach ($bnodes as $key)
                            {
                                $indexedData[$key] = $linkedData[$key];
                            }
                        }
                        else
                        {
                            // let bnodes out of result set
                            $indexedData[$uri] = $this->deleteBnodes($indexedData[$uri]);
                        }
                        // TODO: get all other relevant resources (referenced in any way over x steps)
                        unset($bnodes);
                        */
                        
                        $this->addLogMessage('  --> use: '.$uri.' ('.count($indexedData[$uri]).' elements)');

                        if (isset($testuri) && $uri == $testuri) die('<pre>'.print_r($indexedData, true).'</pre>');

                        unset($linkedData);
                        
                        // save cache for indexed data of uri
                        $this->saveCache(array('data'=>$indexedData, 'name'=>$uri, 'space'=>'FoafpressData', 'time'=>true));
                    }
                }
            }
            else
            {
                $this->addLogMessage('  --> read from cache: '.$uri.' ('.count($indexedData[$uri]).' elements)');
            }
            
        } // end of if (substr($uri, 0 , 1) != '_')

        if (isset($testuri) && $uri == $testuri) die('<pre>'.print_r($indexedData, true).'</pre>');

        if ($indexedData)
        {
            // return Foafpress_resource
            $ARC2TO = clone $this;

            // check alias (e.g. for owl:sameAs)
            if ($alias)
            {
                $indexedData[$alias] = $indexedData[$uri];
                unset($indexedData[$uri]);
            }

            if (isset($testuri) && $uri == $testuri) die('<pre>'.print_r($indexedData, true).'</pre>');

            // merge data
            $this->resource->index = ARC2::getMergedIndex($this->resource->index, $indexedData);
            
            if (isset($testuri) && $uri == $testuri) die('<pre>'.print_r($this->resource->index, true).'</pre>');

            unset($indexedData);

            $ARC2TO->uri = $uri;
            $ARC2TO->updateNamespacePrefix();
            
            if ($alias)
            {
                //TODO: $ARC2TO->includeSameAs($alias);
                $ARC2TO->uri = $alias;
            }
            else
            {
                $ARC2TO->level++;
            }
            
            $this->addLogMessage('now: count '.count($this->resource->index).' -- memory '.intval(memory_get_usage(true)/1024).'kb');
            return $ARC2TO;

        }
        else
        {
            $this->addLogMessage('now: count '.count($this->resource->index).' -- memory '.intval(memory_get_usage(true)/1024).'kb');

            // no data or error, only return requested uri
            return $uri;
        }
    }
    
    /* TODO
     * @since 0.1
     */
    public function includeSameAs($alias = null)
    {
        $sameAs = $this->_nld_owl_sameAs;
        if (is_array($sameAs) && count($sameAs)>0)
        {
            foreach ($sameAs as $sameRes)
            {
                if (!is_object($sameRes))
                    $this->getLinkedData($sameRes, ($alias?$alias:$this->uri));
            }
        }
        
        return;
    }
    
    /**
     * Update Namespace Prefix
     *
     * tries to get the prefix from the namespace of the resource type, and
     * using it as default prefix for the Foafpress object
     *
     * @return string $concept type without namespace
     */
    public function updateNamespacePrefix()
    {
        $concept = false;
        
        if (isset($this->resource->index[$this->uri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type']))
        {
            // get resource type
            $type = $this->resource->index[$this->uri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'][0]['value'];
            // get namespace and concept from type
            $concept = str_replace($this->resource->ns, '', $type);
            $model = substr($type, 0, -1 * strlen($concept));
            $model_ns = array_search($model, $this->resource->ns);
            
            if ($model_ns !== false) $this->ns_prefix = $model_ns;
        }
        
        return $concept;
    }
    
    /* Get data from cache
     *
     * you may implement it to improve performance, use the cache API from your
     * environmental system
     */
    public function getCache(Array $vars)
    {
        return false;
    }
    
    /* Write data to cache
     *
     * you may implement it to improve performance, use the cache API from your
     * environmental system
     */
    public function saveCache(Array $vars)
    {
        return false;
    }
    
    /* TODO
     * @since 0.1
     */
    public function getLiteral(Array $predicates = array(), Array $preferedLanguages = array())
    {
        $languages = $preferedLanguages;
        
        //if (!is_array($predicates)) $predicates = array(strval($predicates));
        if (count($predicates) == 0) return null;
        
        //if (!is_array($languages)) $languages = array(strval($languages));
        
        foreach ($predicates as $p)
        {
            $o = $this->$p;
            if (is_array($o) && count($o) > 0)
            {
                foreach ($languages as $l)
                {
                    if (isset($o[$l]))
                    {
                        return (is_object($o[$l][0]))?$o[$l][0]->uri:$o[$l][0];
                        break;
                    }
                }

                return (is_object($o[0]))?$o[0]->uri:$o[0];
                break;
            }
        }
        
        return null;
    }
    
    /* TODO
     * @since 0.1
     */
    public function getImage(Array $predicates = array(), $useThumbnail = null)
    {
        if (count($predicates) == 0) return null;
        
        $imgSrc = null;
        $imgAlt = null;
        
        foreach ($predicates as $p)
        {
            $p = '_nld_'.$p;
            $pics = $this->$p;
            if (is_array($pics) && count($pics) > 0)
            {
                foreach ($pics as $pic)
                {
                    if (is_object($pic))
                    {
                        $imgSrc = $pic->uri;
                        if ($useThumbnail === true)
                        {
                            $thumbs = $pic->_nld_foaf_thumbnail;
                            if (is_array($thumbs) && count($thumbs) > 0)
                            {
                                $imgSrc = htmlspecialchars($thumbs[0]->uri, ENT_COMPAT, 'UTF-8');
                            }
                        }
                        $imgAlt = htmlspecialchars($pic->getLiteral(array('rdfs_label', 'dc_title', 'foaf_name', 'dc_description')), ENT_COMPAT, 'UTF-8');
                        break;
                    }
                    else
                    {
                        $imgSrc = htmlspecialchars($pic, ENT_COMPAT, 'UTF-8');
                        break;
                    }
                }
                break;
            }
        }
        
        // $this->templateImage = '<img src="##URL##" alt="##DESC##"/>'
        return $imgSrc?str_replace(array('##URL##', '##DESC##'), array($imgSrc, $imgAlt), $this->templateImage):null;
    }
        
    /* Reduce Memory Usage
     *
     * you may implement it to improve performance, use the cache API from your
     * environmental system
     */
    public function reduceMemoryUsage($resource)
    {
        return $resource;
    }
    
    /**
     * listActivity
     *
     * tries to get feeds by checking various ways to relate feeds with foaf
     * profiles.
     *
     * @see http://wiki.foaf-project.org/w/PersonWeblogRssDocumentationIssue
     *
     * @since 0.1
     *
     * @param array $check array with strings of predicates which should be checked
     * @param int $items number of returned activity items
     *
     * @return array with activity items
     */
    public function listActivity(Array $check = array(), $numberItems = null)
    {
        // $ARC2TO = $this;
        
        if (!isset($cacheTimeActivity)) $cacheTimeActivity = $this->cacheTimeActivity;
        
        if (!is_array($check) || count($check) == 0)
            $check = array('seeAlso', 'made', 'weblog', 'account');

        if ($numberItems == null) $numberItems = 50;
        $numberItems = intval($numberItems);

        // get all rdfs:seeAlso
        $rdfs_seeAlso = array();
        if (array_search('seeAlso', $check) !== false)
            $rdfs_seeAlso = $this->_nld_rdfs_seeAlso;

        // get all foaf:made
        $foaf_made = array();
        if (array_search('made', $check) !== false)
            $foaf_made = $this->_nld_foaf_made;

        // get all rdfs:seeAlso in foaf:Document
        $weblog_seeAlso = array();
        if (array_search('weblog', $check) !== false)
        {
            $foaf_weblog = $this->_nld_foaf_weblog;
            foreach($foaf_weblog as $weblog)
            {
                if (is_object($weblog) && $weblog->_nld_rdfs_seeAlso)
                    $weblog_seeAlso = array_merge($weblog_seeAlso, $weblog->_nld_rdfs_seeAlso);
                unset($weblog);
            }
            unset($foaf_weblog);
        }
        
        // get all rdfs:seeAlso in foaf:OnlineAccount
        $account_seeAlso = array();
        if (array_search('account', $check) !== false)
        {
            $foaf_holdsAccount = $this->_nld_foaf_holdsAccount;
            foreach($foaf_holdsAccount as $holdsAccount)
            {
                if (is_object($holdsAccount) && $holdsAccount->_nld_rdfs_seeAlso)
                    $account_seeAlso = array_merge($account_seeAlso, $holdsAccount->_nld_rdfs_seeAlso);
                unset($holdsAccount);
            }
            unset($foaf_holdsAccount);
        }
        
        // check for type, must be rss:channel
        $possibleFeeds = array_unique(array_merge($rdfs_seeAlso, $foaf_made, $weblog_seeAlso, $account_seeAlso));
        unset($rdfs_seeAlso, $foaf_made, $weblog_seeAlso, $account_seeAlso);

        $confirmedFeeds = array();
        
        foreach ($possibleFeeds as $feed)
        {
            if (is_object($feed) &&
                is_array($feed->_nld_rdf_type) &&
                isset($feed->_nld_rdf_type[0]) &&
                $feed->_nld_rdf_type[0] == 'http://purl.org/rss/1.0/channel')
            {
                $confirmedFeeds[$feed->uri] = $feed->getLiteral(array('rdfs_label', 'dc_title'));
            }
        }
        
        unset($possibleFeeds);
        
        $activity = array('feeds'=>$confirmedFeeds, 'stream'=>array());
        
        // load feeds and read items
        $i = 0;
        $sortByDate = array();
        $uniqueUris = array();
        
        foreach ($confirmedFeeds as $feed => $feedTitle)
        {
            $feedIndex = null;
        
            if (false === ($feedIndex = $this->getCache(array('name'=>$feed, 'space'=>'FoafpressActivity', 'time'=>$cacheTimeActivity))))
            {
                // parse feed
                $feedParser = ARC2::getRDFParser();
                $feedParser->parse($feed, null, 0, $this->requestsTimeout);
                if (is_object($feedParser))
                {
                    $feedIndex = $feedParser->getSimpleIndex(0);
                    // save cache
                    $this->saveCache(array('data'=>$feedIndex, 'name'=>$feed, 'space'=>'FoafpressActivity', 'time'=>true));
                }
            }

            if (is_array($feedIndex) && count($feedIndex) > 0)
            {
                // get feed title from feed when resource did not defined any title
                $indexKeys = array_keys($feedIndex);
                if (isset($feedIndex[$indexKeys[0]]['http://purl.org/rss/1.0/title']) && $activity['feeds'][$feed] === null)
                    $activity['feeds'][$feed] = $feedIndex[$indexKeys[0]]['http://purl.org/rss/1.0/title'][0]['value'];
                
                // get feed items
                foreach ($feedIndex as $uri => $content)
                {
                    if (isset($content['http://www.w3.org/1999/02/22-rdf-syntax-ns#type']) && 
                        $content['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'][0]['value'] == 'http://purl.org/rss/1.0/item' &&
                        array_search($content['http://purl.org/rss/1.0/link'][0]['value'], $uniqueUris) === false)
                    {
                        // content
                        $activity['stream'][$i]['source'] = $feed;
                        $activity['stream'][$i]['date'] = (isset($content['http://purl.org/dc/elements/1.1/date']))?strtotime($content['http://purl.org/dc/elements/1.1/date'][0]['value']):0;
                        $activity['stream'][$i]['link'] = htmlspecialchars($content['http://purl.org/rss/1.0/link'][0]['value'], ENT_COMPAT, 'UTF-8');
                        $activity['stream'][$i]['title'] = $content['http://purl.org/rss/1.0/title'][0]['value'];
                        $activity['stream'][$i]['content'] = (isset($content['http://purl.org/rss/1.0/modules/content/encoded']))?$content['http://purl.org/rss/1.0/modules/content/encoded'][0]['value']:null;
                        $activity['stream'][$i]['output'] = '<a href="'.$activity['stream'][$i]['link'].'">'.htmlspecialchars(strip_tags($activity['stream'][$i]['title']), ENT_COMPAT, 'UTF-8').'</a>'; //TODO do not transform html-entities a second time
                        $sortByDate[$i] = $activity['stream'][$i]['date'];
                        // orgacheck
                        $uniqueUris[] = $activity['stream'][$i]['link'];
                        $i++;
                    }
                }
            }
        }
        
        // sort by date desc
        array_multisort($sortByDate, SORT_DESC, $activity['stream']);
        
        // only give back maximum number of items
        $activity['stream'] = array_slice($activity['stream'], 0, $numberItems);
        
        return $activity;
        
    }

    /* Write message to log
     *
     * you may implement it for debugging reasons
     */
    protected function addLogMessage($msg)
    {
        return true;
    }
    
}


