<?php
require_once "lib_constants.inc";
require_once "ldap_config.php";
require_once "LdapUtilities.php";

$appError = 0;
$appErrorMessage = "";
// If there is an error during these functions, whether it is fatal or not, it should be logged to $appErrorMessages.
// mit_search will then pass those error messages back to the client, along with the results.

function mit_search($search)
{
	global $appError;
	global $appErrorMessage;
		
    $query = standard_query($search);

    if ($appError != 1) {
	    $results = do_query($query);	
	    if ($appError != 1) {
			$results = order_results($results, $search);
		}
	}
	return array(array("Results" => $results, "Message" => $appErrorMessage)); // The JSON serialization process needs an outer array to work.
}

function wrap_error_for_JSON($errorMessage)
{
	return array(array("error" => $errorMessage));
}

function order_results($results, $search)
{
    $low_priority = array();
    $high_priority = array();
    foreach($results as $result)
    {
        $item = make_person($result);
        if(has_priority($item, $search))
        {
            $high_priority[] = $item;
        } 
        else
        {
            $low_priority[] = $item;
        }  
    }
    //Alphabetize low_priority array
    usort($low_priority, "compare_people");
    return array_merge($high_priority, $low_priority);
}

function has_priority($item, $search)
{
    $words = preg_split('/\s+/', trim($search));
    if(count($words) == 1)
    {
        $word = strtolower($words[0]);
        $emails = $item['email'];
        foreach($emails as $email)
        {
            $email = strtolower($email);
            if( ($email == $word) || (substr($email, 0, strlen($word)+1) == "$word@") )
            {
                return True;
            }
        }
    }
    return False;
}
/*
function email_query($search)
{
    $words = preg_split('/\s+/', trim($search));
    if(count($words) == 1)
    {
        $word = $words[0];
        if(strpos($words, '@') === False)
        {
            //turns blpatt into blpatt@*
            $word .= '@*';
        }
        return new QueryElement('mail', $word);
    }
}
*/

function standard_query($search)
{
	global $appError;
	global $appErrorMessage;
	
    if (strpos($search, "@") != FALSE)
    {
        if (strpos($search, " ") != FALSE)
        {
            $appError = 1;
			$appErrorMessage = nonLDAPErrorMessage(INVALID_EMAIL_ADDRESS);
            return;
        }
        $emailFilter = EMAIL_FILTER;
        $emailFilter = str_replace("%s", $search, $emailFilter);
        $searchFilter = EMAIL_SEARCH_FILTER;
        $searchFilter = str_replace("%s", $emailFilter, $searchFilter);
        return($searchFilter);
    }
    if (strpbrk(strtolower($search), "abcdefghijklmnopqrstuvwxyz") != FALSE)
    {
		// This function is defined in LdapUtilities.
        return(buildNameAndEmailLDAPQuery($search));
    }
    $search = str_replace("(", "", $search);
    $search = str_replace(")", "", $search);
    $search = str_replace(" ", "", $search);
    $search = str_replace(".", "", $search);
    $search = str_replace("-", "", $search);
    if (($search == null) || (strlen($search) == 0))
    {
        $appError = 1;
		$appErrorMessages = nonLDAPErrorMessage(INVALID_TELEPHONE_NUMBER);
        return;
    }

    $telephoneFilter = TELEPHONE_FILTER;
    $telephoneFilter = str_replace("%s", $search, $telephoneFilter);
    $searchFilter = TELEPHONE_SEARCH_FILTER;
    $searchFilter = str_replace("%s", $telephoneFilter, $searchFilter);
    return($searchFilter);
}

// Returns an array of search results. Returns an empty array if there are no results.
// Puts error messages in $appErrorMessages.
function do_query($query, $search_results=array())
{
	global $appError;
	global $appErrorMessage;
	
    $ldapQueryResultMessage = "";

    try
    {
        $ds = ldap_connect(LDAP_SERVER);
        if ($ds == FALSE)
        {
            throw new Exception("");
        }
        //turn off php Warnings, during ldap search
        //since it complains about search that go over the limit of 100
        $error_reporting = ini_get('error_reporting');
        error_reporting($error_reporting & ~E_WARNING);
        // set a 10 second timelimit
        $sr = ldap_search($ds, LDAP_PATH, $query, array(), 0, 0, SEARCH_TIMELIMIT);
        if ($sr == FALSE)
        {
            throw new Exception("");
        }
        error_reporting($error_reporting);
        $entries = ldap_get_entries($ds, $sr);
        if ($entries == FALSE)
        {
            throw new Exception("");
        }
        if ($ds) {
            $appErrorMessage = generateErrorMessage($ds);
		}
    }
    catch (Exception $e)
    {
        $appError = 1;
        if ($ds) {
			$appErrorMessage = generateErrorMessage($ds);
        }
		else {
			$appErrorMessage = nonLdapErrorMessage(LDAP_SEARCH_ERROR);
		}
        return array(); // Return empty result set.
    }
    
    for ($i = 0; $i < $entries["count"]; $i++)
    {
        $entry = $entries[$i];
        //some ldap entries have no usefull information
        //we dont want to return those
        if(lkey($entry, "sn", True))
        {
            //if one person has multiple ldap records
            //this code attempts to combine the data in the records
            if($old = $search_results[id_key($entry)])
            {
            }
            else
            {
                $old = array();
            }
            $search_results[id_key($entry)] = array_merge($old, $entry);
        }
    }
	
    return $search_results;
}

/*********************************************
 *
 *  this function compares people by firstname then lastname
 *
 *********************************************/
function compare_people($person1, $person2)
{
    if(get_person_propval($person1, 'sn') != get_person_propval($person2, 'sn'))
    {
        return ($person1['sn'] < $person2['sn']) ? -1 : 1;
    }
    elseif(get_person_propval($person1, 'givenname') == get_person_propval($person2, 'givenname'))
    {
        return 0;
    }
    else
    {
        return (get_person_propval($person1, 'givenname') <  get_person_propval($person2, 'givenname')) ? -1 : 1;
    }
}
    
function get_values_from_person($person, $propertyname)
{
	return $person[$propertyname]['Values'];
}

function get_person_propval($person, $propertyname)
{
	$values = get_values_from_person($person, $propertyname);
	return $values[0];
}

function lookup_username($id)
{
    if(strstr($id, '='))
    {
        //look up person by "dn" (distinct ldap name)
        try
        {
            $ds = ldap_connect(LDAP_SERVER);
            if ($ds == FALSE)
            {
                throw new Exception("");
            }
            // set a 10 second timelimit
            $sr = ldap_read($ds, $id, "(objectclass=*)", array(), 0, 0, READ_TIMELIMIT);
//            $sr = ldap_read($ds, $id, "(objectclass=*)");
            if ($sr == FALSE)
            {
                throw new Exception("");
            }
            $entries = ldap_get_entries($ds, $sr);
            if ($entries == FALSE)
            {
                throw new Exception("");
            }
            return make_person($entries[0]);
        }
        catch (Exception $e)
        {
            $appError = 1;
            return(DIRECTORY_UNAVAILABLE);
        }
    }
    else
    {
        $uidFilter = UID_FILTER;
        $uidFilter = str_replace("%s", $id, $uidFilter);
        $searchFilter = UID_SEARCH_FILTER;
        $searchFilter = str_replace("%s", $uidFilter, $searchFilter);
        $tmp = do_query($searchFilter);
        if ($appError == 1)
            return($tmp);
        foreach($tmp as $key => $first)
        {
            return make_person($first);
        }
    }
}

function lkey($array, $key, $single=False)
{
    if ($single)
    {
        return $array[$key][0];
    }
    else
    {
        $result = $array[$key];
        if($result === NULL)
        {
            return array();
        }
        unset($result["count"]);
        return $result;
    }
}

function id_key($info)
{
    if($username = lkey($info, "uid", True))
    {
        return $username;
    }
    else
    {
        return $info["dn"];
    }
}

function make_person($info)
{
    global $personDisplayMapping;
    
    $person = array();
    foreach ($personDisplayMapping as $personDisplay)
    {
        if (($personDisplay[4] == TRUE) || ($personDisplay[7] == TRUE))
        {
            if (strcasecmp($personDisplay[1], "uid") == 0)
            {
                $person[$personDisplay[1]] = array(
					"DisplayName" => $personDisplay[0], 
					"Values" => array(id_key($info))
					);
            }
            else
            {
				// The key for each person should be $personDisplay[1], the ldap key.
				// The value for each person should be a dictionary containing:
				// DisplayName
				// Values (which is usually a list of strings)
                $person[$personDisplay[1]] = array(
					"DisplayName" => $personDisplay[0], 
					"Values" => lkey($info, $personDisplay[1])
					);
            }
        }
    }
/*
  $person = array(
     $personDisplayMapping[0][0]=>lkey($info, $personDisplayMapping[0][1]),
     $personDisplayMapping[1][0]=>lkey($info, $personDisplayMapping[1][1]),
     $personDisplayMapping[2][0]=>lkey($info, $personDisplayMapping[2][1]),
     $personDisplayMapping[3][0]=>lkey($info, $personDisplayMapping[3][1]),
     $personDisplayMapping[4][0]=>lkey($info, $personDisplayMapping[4][1]),
     $personDisplayMapping[5][0]=>lkey($info, $personDisplayMapping[5][1]),
     $personDisplayMapping[6][0]=>lkey($info, $personDisplayMapping[6][1]),
     $personDisplayMapping[7][0]=>lkey($info, $personDisplayMapping[7][1]),
     $personDisplayMapping[8][0]=>lkey($info, $personDisplayMapping[8][1]),
     $personDisplayMapping[9][0]=>lkey($info, $personDisplayMapping[9][1]),
     $personDisplayMapping[10][0]=>lkey($info, $personDisplayMapping[10][1]),
     $personDisplayMapping[11][0]=>lkey($info, $personDisplayMapping[11][1]),
     $personDisplayMapping[12][0]=>lkey($info, $personDisplayMapping[12][1]),
     $personDisplayMapping[13][0]=>lkey($info, $personDisplayMapping[13][1])
  );
*/
    foreach($person["room"] as $room)
    {
        if(!in_array($room, $person["office"]))
        {
            $person["office"][] = $room;
        }
    }
    if ($person['givenname'] != null)
    {
        if ($person["initials"] != null)
        {
            $person["givenname"][0] = $person["givenname"][0] . " " . $person["initials"][0];
        }
    }
    unset($person["initials"]);
    unset($person["room"]);
    unset($person["count"]);

    return $person;
}  


/**                                                  *
 *  a series of classes alowing for the construction *
 *  of ldap queries                                  *
 *                                                   */
abstract class LdapQuery
{
    abstract public function out();

    public static function escape($str)
    {
        $specials = array("*", "+", "=" , ",");
        foreach($specials as $special)
        {
            $str = str_replace($special, "\\" . $special, $str);
        }
        return $str;
    }
}

class LdapQueryList extends LdapQuery
{
    protected $symbol;
    protected $queries=array();

    public function out()
    {
        if ($this->symbol != null)
        {
            $out = '(' . $this->symbol;
        }
        foreach($this->queries as $query)
        {
	    $out .= $query->out();
        }
        if ($this->symbol != null)
        {
            $out .= ')';
        }
        return $out;
    }
}   
    
class QueryElementList extends LdapQueryList
{
    public function __construct($cond_arr=array())
    {
        foreach($cond_arr as $field => $value)
        {
	    $this->add($field, $value);
        }
    }

    public function add($field, $value)
    {
         $this->queries[] = new QueryElement($field, $value);
         return $this;
    }
}

class LdapFilter extends QueryElementList
{

    public function _($field, $value)
    {
        return $this->add($field, $value);
    }
}

class LdapAndFilter extends QueryElementList
{
    protected $symbol = '&';

    public function _AND($field, $value)
    {
        return $this->add($field, $value);
    }
}

class LdapOrFilter extends QueryElementList
{
    protected $symbol = '|'; 

    public function _OR($field, $value)
    {
        return $this->add($field, $value);
    }
}

class JoinQuery extends LdapQueryList
{

    public function __construct()
    {
        $this->queries = func_get_args();
    }
}

class JoinAndQuery extends JoinQuery
{
    protected $symbol = '&';

    public function _AND(LdapQuery $query)
    {
        $this->queries[] = $query;
        return $this;
    }
}

class JoinOrQuery extends JoinQuery
{
    protected $symbol = '|';

    public function _OR(LdapQuery $query)
    {
        $this->queries[] = $query;
        return $this;
    }
}

class QueryElement extends LdapQuery
{
    protected $field;
    protected $value;
    
    static private $special_chars = array( '(', ')' );

    public function __construct($field, $value)
    {
        $this->field = $field;
   
        //convert all multiple wildcards to a single wildcard
        $this->value = preg_replace('/\*+/', '*', $value);
    }
  
    public function out()
    {
        $escaped_value = $this->value;
        $escaped_value = str_replace("\\", "\\\\", $escaped_value);
        foreach(self::$special_chars as $char)
        {
            $escaped_value = str_replace($char, "\\" . $char, $escaped_value);
        }
        return '(' . $this->field . '=' . $escaped_value . ')';
    }
}

class RawQuery extends LdapQuery
{
    protected $raw_query;
  
    public function __construct($raw_query)
    {
        $this->raw_query = $raw_query;
    }

    public function out()
    {
        return $this->raw_query;
    }
}  

function ldap_die($message)
{
    throw new DataServerException($message);
}

function nonLDAPErrorMessage($errorCode)
{
	$message = "Unknown error.";
  	$errorCodes = array(
   		// Errors not from LDAP but related to LDAP queries.
		INVALID_EMAIL_ADDRESS => "Invalid email address.",
		INVALID_TELEPHONE_NUMBER => "Invalid phone number.",
		LDAP_SEARCH_ERROR => "There was a problem querying the server.",
    );
	if (isset($errorCodes[$errorCode]))
	{
		$message = $errorCodes[$errorCode];
	}
	return $message;
}

?>
