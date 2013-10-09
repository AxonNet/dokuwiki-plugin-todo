/**
 * @date 20130405 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                replace old sack() method with new jQuery method and use post instead of get - see https://www.dokuwiki.org/devel:jqueryfaq \n
 * @date 20130407 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                use jQuery for finding the elements \n
 * @date 20130408 Christian Marg <marg@rz.tu-clausthal.de> \n
 *                change only the clicked todo item instead of all items with the same text \n
 * @date 20130408 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                migrate changes made by Christian Marg to current version of plugin (use jQuery) \n
 * @date 20130410 by Leo Eibler <dokuwiki@sprossenwanne.at> / http://www.eibler.at \n
 *                bugfix: encoding html code (security risk <todo><script>alert('hi')</script></todo>) - bug reported by Andreas \n
 * @date 20130413 Christian Marg <marg@rz.tu-clausthal.de> \n
 *                bugfix: chk.attr('checked') returns checkbox state from html - use chk.is(':checked') - see http://www.unforastero.de/jquery/checkbox-angehakt.php \n
 * @date 20130413 by Leo Eibler <dokuwiki@sprossenwanne.at> / http://www.eibler.at \n
 *                bugfix: config option Strikethrough \n
 */

/**
 * lock to prevent simultanous requests
 */
var todoplugin_locked = {clickspan: false, todo: false};
/**
 * @brief onclick method for span element
 * @param {jQuery} $span  the jQuery span element
 * @param {string} id     the page
 * @param {int}    strike strikethrough activated (1) or not (0) - see config option Strikethrough
 */
function clickSpan($span, id, strike) {
    //skip when locked
    if (todoplugin_locked.clickspan || todoplugin_locked.todo) {
        return;
    }
    todoplugin_locked.clickspan = true;

    //Find the checkbox node we need
    var $chk;
    //var $preve = jQuery(span).prev();
    var $preve = $span.prev();
    while ($preve) {
        if ($preve.is("input")) {
            $chk = $preve;
            break;
        }
        $preve = $preve.prev();
    }
    if ($chk.is("input")) {
        $chk.attr('checked', !$chk.is(':checked'));
        todo($chk, id, strike);
        //chk.checked = !chk.checked;
    } else {
        alert("Appropriate javascript element not found.");
    }

}

/**
 * @brief onclick method for input element
 * @param {jQuery} $chk    the jQuery input element
 * @param {string} path    the page
 * @param {int}    strike  strikethrough activated (1) or not (0) - see config option Strikethrough
 */
function todo($chk, path, strike) {
    //skip when locked
    if (todoplugin_locked.todo) {
        return;
    }
    todoplugin_locked.todo = true;

    /**
     * +input[checkbox]
     * +span.todotext
     * -input[hidden]
     * -del
     * --span.todoinnertext
     * ---anchor with text or text only
     */

    var $inputTodohiddentext = $chk.nextAll("span.todotext").first().find("input.todohiddentext"),
        $spanTodoinnertext = $chk.nextAll("span.todotext").first().find("span.todoinnertext"),
        index = $chk.data('index'),
        checked = $chk.is(':checked'),
        date = $chk.data('date');

    // if the data-index attribute is set, this is a call from the page where the todos are defined
    // otherwise this is a call from searchpattern dokuwiki plugin rendered page
    if (index === undefined) index = -1;

    if ($spanTodoinnertext[0] && $inputTodohiddentext[0]) {
        if (checked) {
            if (strike && !$spanTodoinnertext.parent().is("del")) {
                $spanTodoinnertext.wrap("<del></del>");
            }
        } else {
            if ($spanTodoinnertext.parent().is("del")) {
                $spanTodoinnertext.unwrap();
            }
        }

        var whenCompleted = function (data) {
            //update date after edit and show alert when needed
            if (data.date)
                jQuery('input.todocheckbox').data('date', data.date);

            if (data.message)
                alert(data.message);

            todoplugin_locked = {clickspan: false, todo: false};
        };

        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_todo',
                index: index,
                path: path,
                checked: checked ? "1" : "0",
                origVal: $inputTodohiddentext.val().replace(/\+/g, " "),
                date: date
            },
            whenCompleted,
            'json'
        );
    } else {
        alert("Appropriate javascript element not found.\nReverting checkmark.");
    }

}
