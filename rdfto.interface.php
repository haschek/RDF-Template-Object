<?php

/**
 * Interface for an RDF Template Object
 *
 * WHAT IT SHOULD USED TO BE
 * It's used to provide fast access to RDF data from other PHP templates, e.g.
 * $person->foaf_name[0] returns the foaf:name predicate of a foaf:Person which
 * wrapped by $person.
 *
 * WHAT IT IS NOT
 * It is not a template to fill out to create RDF data.
 *
 * @version 0.1
 * @author Michael Haschke, http://eye48.com/
 * @license GNU General Public License 2, http://www.gnu.org/licenses/gpl-2.0.html
 **/
interface RDF_Template_Object
{

    /* vars cannot be defined in a interface, but some variables should be
     * implemented and used
     */
     
    /* $uri should be implemented and
     * it is used to contain the resource URI which is wrapped by the object
     */
     
    /* public $uri; // contains the resource URI which is wrapped by the object */
    /* public $ns_prefix; // contains namespace prefix of model which defines the type of the currently wrapped object  */

    /* Contructor for RDF Template Object
     *
     * Instantiate the class and saves important variables for the API
     * environment used for.
     *
     * @param Array $environment
     */
    public function __construct(Array $environment);
    
    /* Magically get vars from the used RDF parser library or transform
     * requests for predicates of the wrapped resource. Returns arrays of the
     * objects.
     *
     * Use array of string/other datatypes to return literals and data type
     * properties, return array of RDF Template Objects for object properties.
     *
     * Example:
     * $person->foaf_name describes the data from foaf:name, using underscore as separator
     * for $person->name the default namespace prefix from $ns_prefix should be used
     *
     * @param string $name
     */
    public function __get($name);
    
    /* Transform RDF Template Objects to a string representation, usally used to
     * compare those objects
     */
    public function __toString();
    
    /* fetch RDF data for unknown objects which got an URI, return an RDF
     * Template Object
     *
     * @param string $uri
     * @param string $alias
     */
    public function getLinkedData($uri, $alias = null);
    
    /* fetch data from URIs of objects which are semantically defined as same
     * as the object wrapped by the current RDF Template Object
     *
     * @param string $alias
     */
    public function includeSameAs($alias = null);
    
    /* Update Namespace Prefix
     *
     * write it to $ns_prefix
     */
    public function updateNamespacePrefix();
    
    /* Get data from cache
     */
    public function getCache(Array $vars);
    
    /* Write data to cache
     */
    public function saveCache(Array $vars);
    
    /* Return literal property
     *
     * return the string of the literal
     */
    public function getLiteral(Array $predicates = array(), Array $preferedLanguages = array());
    
    /* Return image property
     *
     * return String with image representation
     *
     * @param Array $predicates
     * @param boolean $useThumbnail
     */
    public function getImage(Array $predicates = array(), $useThumbnail = null);
        
}


/**
 * Interface for an RDF Template Helper
 *
 * provides a defination of function which could be implemented as usable tools
 * within the RDF Template Object.
 *
 * @version 0.1
 * @author Michael Haschke, http://eye48.com/
 * @license GNU General Public License 2, http://www.gnu.org/licenses/gpl-2.0.html
 **/
interface RDF_Template_Helper
{

    /* Reduce Memory Usage
     *
     * delete data from the resource object to save memory space
     */
    public function reduceMemoryUsage($resource);
    
    /* List activity
     *
     * evaluating feeds related to object and give back an array with updates
     *
     * @param Array $checkPredicates
     * @param int $numberItems
     */
    public function listActivity(Array $checkPredicates = array(), $numberItems = null);
    
}


