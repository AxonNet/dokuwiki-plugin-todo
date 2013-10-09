<?php

/**
 * ToDo Action Plugin: Inserts button for ToDo plugin into toolbar
 *
 * Original Example: http://www.dokuwiki.org/devel:action_plugins
 * @author     Babbage <babbage@digitalbrink.com>
 * @date 20130405 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                replace old sack() method with new jQuery method and use post instead of get \n
 * @date 20130408 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                remove getInfo() call because it's done by plugin.info.txt (since dokuwiki 2009-12-25 Lemming)
 */

if(!defined('DOKU_INC')) die();
/**
 * Class action_plugin_todo registers actions
 */
class action_plugin_todo extends DokuWiki_Action_Plugin {

    /**
     * Register the eventhandlers
     */
    public function register(&$controller) {
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'insert_button', array());
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, '_ajax_call', array());
    }

    /**
     * Inserts the toolbar button
     */
    public function insert_button(&$event, $param) {
        $event->data[] = array(
            'type' => 'format',
            'title' => $this->getLang('qb_todobutton'),
            'icon' => '../../plugins/todo/todo.png',
            'key' => 't',
            'open' => '<todo>',
            'close' => '</todo>',
            'block' => false,
        );
    }

    /**
     * Handles ajax requests for to do plugin
     *
     * @brief This method is called by ajax if the user clicks on the to-do checkbox or the to-do text.
     * It sets the to-do state to completed or reset it to open.
     *
     * POST Parameters:
     *   index    int the position of the occurrence of the input element (starting with 0 for first element/to-do)
     *   checked    int should the to-do set to completed (1) or to open (0)
     *   path    string id/path/name of the page
     *
     * @date 20131008 Gerrit Uitslag <klapinklapin@gmail.com> \n
     *                move ajax.php to action.php, added lock and conflict checks and improved saving
     * @date 20130405 Leo Eibler <dokuwiki@sprossenwanne.at> \n
     *                replace old sack() method with new jQuery method and use post instead of get \n
     * @date 20130407 Leo Eibler <dokuwiki@sprossenwanne.at> \n
     *                add user assignment for todos \n
     * @date 20130408 Christian Marg <marg@rz.tu-clausthal.de> \n
     *                change only the clicked to-do item instead of all items with the same text \n
     *                origVal is not used anymore, we use the index (occurrence) of input element \n
     * @date 20130408 Leo Eibler <dokuwiki@sprossenwanne.at> \n
     *                migrate changes made by Christian Marg to current version of plugin \n
     *
     *
     * @param Doku_Event $event
     * @param mixed $param not defined
     */
    public function _ajax_call(&$event, $param) {
        global $ID, $DATE;

        if($event->data !== 'plugin_todo') {
            return;
        }
        //no other ajax call handlers needed
        $event->stopPropagation();
        $event->preventDefault();

        #Variables
        // by einhirn <marg@rz.tu-clausthal.de> determine checkbox index by using class 'todocheckbox'

        if(isset($_REQUEST['index'], $_REQUEST['checked'], $_REQUEST['path'])) {
            // index = position of occurrence of <input> element (starting with 0 for first element)
            $index = (int) $_REQUEST['index'];
            // checked = flag if input is checked means to do is complete (1) or not (0)
            $checked = urldecode($_REQUEST['checked']);
            // path = page ID (name)
            $ID = cleanID(urldecode($_REQUEST['path']));
        } else {
            return;
        }
        // origVal = urlencoded original value (in the case this is called by dokuwiki searchpattern plugin rendered page)
        $origVal = '';
        if(isset($_REQUEST['origVal'])) $origVal = urldecode($_REQUEST['origVal']);

        $date = 0;
        if(isset($_REQUEST['date'])) $date = (int) $_REQUEST['date'];

        $INFO = pageinfo();

        #Determine Permissions
        if(auth_quickaclcheck($ID) < AUTH_EDIT) {
            echo "You do not have permission to edit this file.\nAccess was denied.";
            return;
        }
        // Check, if page is locked
        if(checklock($ID)) {
            $this->printJson(array('message' => 'The page is currently locked.'));
        }

        //conflict check
        if($date != 0 && $INFO['meta']['date']['modified'] > $date) {
            $this->printJson(array('message' => 'A newer version of this page is available, refresh your page before trying again.'));
            return;
        }

        #Retrieve Page Contents
        $wikitext = rawWiki($ID);

        #Determine position of tag
        $contentChanged = false;

        if($index >= 0) {
            $index++;
            // index is only set on the current page with the todos
            // the occurances are counted, untill the index-th input is reached which is updated
            $todoTagStartPos = strnpos($wikitext, '<todo', $index);
            $todoTagEndPos = strpos($wikitext, '>', $todoTagStartPos) + 1;
            if($todoTagEndPos > $todoTagStartPos) {
                $contentChanged = true;
            }
        } else {
            // this will happen if we are on a dokuwiki searchpattern plugin summary page
            $checkedpattern = $checked ? '' : '*#[^>]';
            $pattern = '/(<todo[^#>]' . $checkedpattern . '*>(' . preg_quote($origVal) . '<\/todo[\W]*?>))/';

            $x = preg_match_all($pattern, $wikitext, $spMatches, PREG_OFFSET_CAPTURE);
            if($x && isset($spMatches[0][0])) {
                // yes, we found matches and index is in a valid range
                $todoTagStartPos = $spMatches[1][0][1];
                $todoTagEndPos = $spMatches[2][0][1];

                $contentChanged = true;
            }
        }

        // Modify content
        if($contentChanged) {
            // update text
            $oldTag = substr($wikitext, $todoTagStartPos, $todoTagEndPos - $todoTagStartPos);
            $newTag = $this->_buildTodoTag($oldTag, $checked);
            $wikitext = substr_replace($wikitext, $newTag, $todoTagStartPos, ($todoTagEndPos - $todoTagStartPos));

            // save Update (Minor)
            lock($ID);
            saveWikiText($ID, $wikitext, 'Checkbox Change', $minoredit = true);
            unlock($ID);

            $return = array('date' => @filemtime(wikiFN($ID)));
            $this->printJson($return);
        }

    }

    private function printJson($return) {
        $json = new JSON();
        echo $json->encode($return);
    }

    /**
     * @brief gets current to-do tag and returns a new one depending on checked
     * @param $todoTag    string current to-do tag e.g. <todo @user>
     * @param $checked    int check flag (todo completed=1, todo uncompleted=0)
     * @return string new to-do completed or uncompleted tag e.g. <todo @user #>
     */
    private function _buildTodoTag($todoTag, $checked) {
        $x = preg_match('%<todo([^>]*)>%i', $todoTag, $matches);
        $newTag = '<todo';
        if($x) {
            if(($userPos = strpos($matches[1], '@')) !== false) {
                $submatch = substr($todoTag, $userPos);
                $x = preg_match('%@([-.\w]+)%i', $submatch, $matchinguser);
                if($x) {
                    $newTag .= ' @' . $matchinguser[1];
                }
            }
        }
        if($checked == 1) {
            $newTag .= ' #';
        }
        $newTag .= '>';
        return $newTag;
    }

    /**
     * @brief Convert a string to a regex so it can be used in PHP "preg_match" function
     * from dokuwiki searchpattern plugin
     */
    private function _todoStr2regex($str) {
        $regex = ''; //init
        for($i = 0; $i < strlen($str); $i++) { //for each char in the string
            if(!ctype_alnum($str[$i])) { //if char is not alpha-numeric
                $regex = $regex . '\\'; //escape it with a backslash
            }
            $regex = $regex . $str[$i]; //compose regex
        }
        return $regex; //return
    }

}

if(!function_exists('strnpos')) {
    /**
     * Find position of $occurance-th $needle in haystack
     */
    function strnpos($haystack, $needle, $occurance, $pos = 0) {
        for($i = 1; $i <= $occurance; $i++) {
            $pos = strpos($haystack, $needle, $pos) + 1;
        }
        return $pos - 1;
    }
}