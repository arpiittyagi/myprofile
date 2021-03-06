<?php
/*
 *  Copyright (C) 2012 MyProfile Project
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal 
 *  in the Software without restriction, including without limitation the rights 
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell 
 *  copies of the Software, and to permit persons to whom the Software is furnished 
 *  to do so, subject to the following conditions:

 *  The above copyright notice and this permission notice shall be included in all 
 *  copies or substantial portions of the Software.

 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, 
 *  INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A 
 *  PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT 
 *  HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION 
 *  OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE 
 *  SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
 
require 'include.php';

$ret = '';
$notification = '';

// fetch the WebID of the wall's owner
if ((isset($_REQUEST['user'])) && ((strlen($_REQUEST['user']) > 0) && ($_REQUEST['user'] != 'local'))) {
    check_auth(IDP, $page_uri);
    
    $owner_webid = get_webid_by_hash(trim($_REQUEST['user']));
    // fetch owner's profile
    $profile = new MyProfile($owner_webid, $base_uri, SPARQL_ENDPOINT);
    $profile->load();
    $owner_name = $profile->get_name();
    $feed_hash = get_feed_by_hash($_REQUEST['user']);       
    $owner_hash = $_REQUEST['user'];
        
    // display private wall only if the requesting user is a friend or the wall owner
    if (($profile->is_friend($_SESSION['webid'])) || ($_SESSION['user_hash'] == $_REQUEST['user'])) {
        $feed_hash = get_feed_by_hash($_REQUEST['user']);       
        $owner_hash = $_REQUEST['user'];
        
        // mark all wall messages as read when the user checks his personal wall
        if ($_SESSION['webid'] == $owner_webid) {
            $query = "UPDATE pingback_messages SET new='0' WHERE ";
            $query .= "to_hash='" . mysql_real_escape_string($_SESSION['user_hash']). "' ";
            $query .= "AND wall='1'";
            $result = mysql_query($query);

            if (!$result) {
                $ret  .= error('Database error while trying to update message status!');
            } else if ($result !== true) {
                mysql_free_result($result);
            }

            $messages = get_msg_count($_SESSION['webid']);
            $wall_msg = get_msg_count($_SESSION['webid'], 1, 1);
        }
    } else {
        // display a warning for the user
        $warning = true;
    }
} else {
    // generic wall
    $feed_hash = 'local';
    $owner_webid = 'local';
    $owner_hash = 'local';
}

// delete a post
if (isset($_REQUEST['del'])) {
    // verify if we're logged in or not
    check_auth(IDP, $page_uri);
    
    $notification .= delete_message($_SESSION['webid'], $_REQUEST['del']);
}

// ADD a post
if (isset($_REQUEST['comment'])) {
	// verify if we're logged in or not
	check_auth(IDP, $page_uri);
    if ((isset($_REQUEST['user'])) && (strlen($_REQUEST['user']) > 0))
        $to_hash = $_REQUEST['user'];
    else
        $to_hash = 'local';

    // only post if we have a message
    if (strlen($_REQUEST['comment']) > 1) {
        // Limit the message to 10k characters
        $msg = trim(substr($_REQUEST['comment'], 0, 10000));
                
        // Get the list of mentioned WebIDs from the message 
        preg_match_all("/<(.*)>/Ui", $msg, $out, PREG_PATTERN_ORDER);  
        $webids = $out[1];

        // Save the time of the request
        $time = time();
        
        if (isset($_REQUEST['new'])) {
            // Insert into databse
            $query = "INSERT INTO pingback_messages SET ";
            $query .= "date='" . $time . "', ";
            $query .= "updated='" . $time . "', ";
            $query .= "etag='" . compute_etag($time). "', ";
            $query .= "from_uri = '" . mysql_real_escape_string($_SESSION['webid']) . "', ";
            $query .= "to_hash='" . $to_hash . "', ";
            if ($owner_webid != 'local')
                $query .= "to_uri = '" . mysql_real_escape_string($owner_webid) . "', ";
            $query .= "name = '" . mysql_real_escape_string($_SESSION['usr']) . "', ";
            $query .= "pic = '" . mysql_real_escape_string($_SESSION['img']) . "', ";
            $query .= "msg = '" . mysql_real_escape_string($msg) . "', ";
            $query .= "wall='1'";

            $result = mysql_query($query);
            if (!$result) {
                $ret  .= error('Database error while trying to insert new message!');
            } else {
                mysql_free_result($result);
               
                // update etags
                $ret .= update_etags($time, $to_hash);
     
                // send a notification to each mentioned user
                foreach ($webids as $to) {
                    $ping_msg = 'I have just mentioned you in a ';
                    $ping_msg .= ($owner_webid != 'local') ? 'private':'public';
                    $ping_msg .=' wall post. You can see it here: ' . $base_uri . '/wall.php?user=' . $to_hash;
                    // send only if the source != target
                    if ($_SESSION['webid'] != $to)
                        sendPing($to, $ping_msg, $base_uri, false);
                }
            }

        }

        // Update the message with new text
        if (isset($_REQUEST['edit'])) {
            $query = "UPDATE pingback_messages SET ";
            $query .= "updated='" . $time . "', "; 
            $query .= "msg = '" . mysql_real_escape_string($msg) . "' ";
            $query .= "WHERE id = '" . mysql_real_escape_string($_REQUEST['edit']) . "' ";
            $query .= "AND from_uri = '" . mysql_real_escape_string($_SESSION['webid']) . "'";

            $result = mysql_query($query);
            if (!$result) {
                $ret  .= error('Database error while updating post!');
            } else if ($result !== true) {
                mysql_free_result($result);
            }

            // update etag for wall posts
            $ret .= update_etags($time, $to_hash);
        }

        // Ugly hack until we implement proper caching
        // Update all previous posts with fresh profile data (name and pic)
        $query = "UPDATE pingback_messages SET "; 
        $query .= "name = '" . mysql_real_escape_string($_SESSION['usr']) . "', ";
        $query .= "pic = '" . mysql_real_escape_string($_SESSION['img']) . "' ";
        $query .= "WHERE from_uri = '" . mysql_real_escape_string($_SESSION['webid']) . "'";

        $result = mysql_query($query);
        if (!$result) {
            $ret .= error('Database error while updating user info!');
        } else if ($result !== true) {
            mysql_free_result($result);
        }
    } else {
        $ret .= warning("You cannot post an empty message. Try writing something below...");
    }
}

// Form allowing to post messages on the wall
if (isset($_SESSION['webid'])) {
    $form_area = "<form name=\"write_wall\" method=\"POST\" action=\"" . htmlentities($_SERVER['PHP_SELF']) . "\">\n";
    $form_area .= "<input type=\"hidden\" name=\"user\" value=\"" . $owner_hash . "\" />\n";
    $form_area .= "<input type=\"hidden\" name=\"new\" value=\"1\" />\n";
    $form_area .= "<table border=\"0\">\n";
    $form_area .= "<tr valign=\"top\">\n";
    $form_area .= "   <td style=\"width: 90px\"><p><a href=\"view.php?uri=" . urlencode($_SESSION["webid"]) . "\" target=\"_blank\">\n";
    $form_area .= "       <img class=\"rounded\" title=\"" . $_SESSION['usr'] . "\" alt=\"" . $_SESSION['usr'] . "\" width=\"64\" src=\"" . $_SESSION['img'] . "\" />\n";
    $form_area .= "   </a></p></td>\n";
    $form_area .= "   <td>\n";
    $form_area .= "       <table border=\"0\">\n"; 
    $form_area .= "       <tr><td><p><b>What's on your mind, <a href=\"view.php?uri=" . urlencode($_SESSION["webid"]) . "\" target=\"_blank\">" . $_SESSION['usr'] . "</a>?</b></p></td></tr>\n";
    $form_area .= "       <tr><td><textarea id=\"comment\" name=\"comment\" onfocus=\"textAreaResize(this)\" class=\"textarea-wall\"></textarea></td></tr>\n";
    $form_area .= "       <tr><td><input class=\"btn btn-primary\" type=\"submit\" name=\"submit\" value=\" Post \" /></td></tr>\n";
    $form_area .= "       </table>\n";
    $form_area .= "   </td>\n";
    $form_area .= "</tr>\n";
    $form_area .= "</table>\n";
    $form_area .= "</form>\n";
} else {
    $form_area = "<p><font style=\"font-size: 1.3em;\"><a href=\"" . IDP . "" . $page_uri . "\">Login</a> with your WebID to post messages.</font></p>\n";
}

// By default there are no posts to display
$rows = 0;

// Limit number of displayed messages to a default value
$limit = 50;

// Compute the offset based on user request (display older/newer messages)
if (isset($_REQUEST['offset']))
    $prev_offset = $_REQUEST['offset'];
else
    $prev_offset = 0;
    
if (isset($_REQUEST['older']))
    $offset = $prev_offset + $limit;
else if (isset($_REQUEST['newer']))
    $offset = $prev_offset - $limit;
else
    $offset = 0;

// display news feed for a certain user
if ((isset($_SESSION['webid'])) && (isset($_REQUEST['activity']))) {
    $webids = sparql_get_people_im_friend_of($_SESSION['webid'], SPARQL_ENDPOINT);
    // Prepare the activity stream SQL query only if the user has friends (foaf:knows)
    if (sizeof($webids) > 0) {
        $query = 'SELECT * FROM pingback_messages WHERE to_hash IS NOT NULL AND wall=\'1\' AND (';
        foreach ($webids as $key => $from) {
            $add = ($key > 0) ? ' OR' : '';
            $query .= $add . " from_uri='" . mysql_real_escape_string($from) . "'";
        }
        $query .= ' OR from_uri="' . mysql_real_escape_string($_SESSION['webid']) . '") ORDER by date DESC LIMIT ' . $limit;
        // Contains the offset value for fetching wall messages
        if (isset($offset))
            $query .= ' OFFSET ' . mysql_real_escape_string($offset);
            
        $result = mysql_query($query);

        if (!$result) 
            $ret .= error('Unable to connect to the database, to display Activity Stream!');
        else
            $rows = mysql_num_rows($result);

        $title = 'News Feed';
    }
} else {
    // get the last 50 wall messages for a user
    $query = 'SELECT * FROM pingback_messages WHERE to_hash=\'' . mysql_real_escape_string($owner_hash) . '\' AND wall=\'1\' ORDER by date DESC LIMIT ' . $limit;
    // Contains the offset value for fetching wall messages
    if (isset($offset))
        $query .= ' OFFSET ' . mysql_real_escape_string($offset);   
    
    $result = mysql_query($query);

    if (!$result)
        $ret .= error('Unable to connect to the database, to display wall posts!');
    else
        $rows = mysql_num_rows($result);

    // Display the wall's title
    if ((isset($owner_name)) && (strlen($owner_name) > 0))
        $title = $owner_name . "'s Wall";
    else
        $title = "MyProfile Public Wall";

}

// Page title (User's Wall)
$ret .= "<div>";
$ret .= "<p><font align=\"left\" style=\"font-size: 2em; text-shadow: 0 1px 1px #cccccc;\">" . $title . "</font></p>\n";
$ret .= "<p>Subscribe now using this <a href=\"" . $base_uri . "/atom.php?id=" . $owner_hash . "\">Atom feed</a>.</p>\n";
$ret .= "</div>";

// main page
$ret .= "<div class=\"container\">\n";

// Add notification message
if (strlen($notification) > 0)
    $ret .= $notification;

// Add message form 
$ret .= $form_area;

// Display warning if the user isn't allowed to view a certain wall
if (isset($warning)) {
    $ret .= "<h3>You are not allowed to see this page because you are not a friend of ";
    $ret .= "<a href=\"view.php?uri=" . urlencode($owner_webid) . "\">" . $profile->get_name() . ".</a></h3>";
} else if ($rows == 0){
    // There are no messages on the wall
    $ret .= "<p><font style=\"font-size: 1.3em;\">There are no messages.</font></p>\n";
} else {
    // Display messages
    $ret .= "<form method=\"GET\" action=\"\">\n";
    $ret .= "<input type=\"hidden\" name=\"user\" value=\"" . htmlspecialchars($owner_hash) . "\" />\n";    
    
    
    // Get total number of messages specific to the given hash
    $total = count_msg_by_hash($owner_hash);
    
    // populate table
    $i = 0;
    while ($row = mysql_fetch_assoc($result)) {
        // get name
        $name = $row['name'];
        if ($name == '[NULL]')
            $name = $row['name'];
        // get picture
        $pic = $row['pic'];
        // get the date and multiply by 1000 for milliseconds, otherwise moment.js breaks
        $timestamp = $row['date'] * 1000;

        // to whom it is addressed
        if (strlen($row['to_uri']) > 0) {
            $to_person = new MyProfile($row['to_uri'], $base_uri, SPARQL_ENDPOINT);
            $to_person->load();
            $to_name = $to_person->get_name();
        } else {
            $to_name = 'MyProfile';
        }

        // replace WebIDs with actual names and links to the WebID
        $msg = preg_replace_callback("/<(.*)>/Ui", "preg_get_handle_by_webid", $row["msg"]);

        // store everything in this table
        $ret .= "<table border=\"0\">\n";

        // add horizontal line to separate messages
        $ret .= "<tr><td></td><td>\n";
        $ret .= "<a name=\"post_" . $row['id'] . "\"><hr style=\"border: none; height: 1px; color: #cccccc; background: #cccccc;\"/></a>\n";
        $ret .= "</td></tr>\n";
        
        $ret .= "<tr valign=\"top\">\n";
        $ret .= "<td width=\"80\" align=\"center\">\n";
        // image
        $ret .= "<a class=\"avatar-link\" href=\"view.php?uri=" . urlencode($row['from_uri']) . "\" target=\"_blank\"><img title=\"" . $name . "\" alt=\"" . $name . "\" width=\"50\" src=\"" . $pic . "\" class=\"rounded\" property=\"sioc:avatar\"/></a>\n";
        $ret .= "</td>\n";
        $ret .= "<td>";
        $ret .= "<table border=\"0\" class=\"table-wall\">\n";
        $ret .= "<tr valign=\"top\" class=\"wall-post\">\n";
        $ret .= "<td class=\"wall-post\">\n";
        // author's name
        $ret .= "<b><a href=\"view.php?uri=" . urlencode($row['from_uri']) . "\" target=\"_blank\" style=\"font-color: black;\">";
        $ret .= "   <span property=\"sioc:UserAccount\">" . $name . "</span>";
        $ret .= "</a></b> wrote ";       
        // activity stream
        if (isset($_REQUEST['activity'])) {
            $ret .= "on <a href=\"wall.php?user=" . $row['to_hash'] . "\" target=\"_blank\" style=\"font-color: black;\">";
            $ret .= $to_name . "'s Wall ";
            $ret .= "</a>";
        }
        // time of post
        $ret .= "<font color=\"grey\">";
        $ret .= "<span id=\"date_" . $row['id'] . "\">";
        $ret .= "<script type=\"text/javascript\">$('#date_" . $row['id'] . "').text(moment(" . $timestamp . ").from());</script>";
        $ret .= "</span></font>\n";
        $ret .= "</td>\n";
        $ret .= "</tr>\n";
        // message
        $ret .= "<tr class=\"wall-post\">\n";
        $ret .= "<td class=\"wall-post\"><pre id=\"message_" . $row['id'] . "\"><span property=\"sioc:Post\" id=\"message_text_" . $row['id'] . "\">\n";
        $ret .= put_links($msg);
        $ret .= "</span></pre></td>\n";
        $ret .= "</tr>\n";
        // show options only if we are the source of the post
        $ret .= "<tr class=\"wall-post\">\n";
        $ret .= "<td class=\"wall-post\"><small>";
        if (
            isset($_SESSION['webid'])
            && (
                ($_SESSION['webid'] == $row['from_uri'])
                || (
                    ($_SESSION['webid'] == $row['to_uri'])
                    && (isset($_REQUEST['user']))
                    && ($_REQUEST['user'] != 'local')
                )
            )
        ) {
            $add = '?user=' . $owner_hash;
            // add option to edit post
            $ret .= "<a onClick=\"updateWall('message_text_" . $row['id'] . "', 'wall.php" . $add . "', '" . $row['id'] . "')\" style=\"cursor: pointer;\">Edit</a>";
            // add option to delete post
            $ret .= " <a href=\"wall.php" . $add . "&del=" . $row['id'] . "\">Delete</a>\n";
        }
        
        // show vote counters and buttons for logged users
        $ret .= add_vote_buttons($row['id']);
        
        $ret .= "</small></td>\n";
        $ret .= "</tr>\n";
        $ret .= "</table>\n";
        $ret .= "</td>\n";
        $ret .= "</tr>\n";
        $ret .= "</table>\n";
    $i++;
    }
    mysql_free_result($result);

    $ret .= "</form>\n";
    $ret .= "</div>\n";
}

// prepare etag
$etag_array = get_etag($owner_hash);

$lastmod = gmdate('D, d M Y H:i:s \G\M\T', $etag_array['date']);
$etag = $etag_array['etag'];

$ifmod = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] == $lastmod : null; 
$iftag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] == $etag : null; 

if (($ifmod || $iftag) && ($ifmod !== false && $iftag !== false)) { 
    header('Not Modified',true,304);
} else {
    header("Last-Modified: $lastmod"); 
    header("ETag: \"" . $etag . "\"");
}

echo $ret;
?>
<script>
$(document).ready(do_autocomplete("comment"));
</script>  
