<?php

define("EMAIL_HEADER", 'background-color: #D1DCFF; font-family: helvetica;
       font-size: 160%; border-top: 1px solid #262c40; padding: 2px');
define("DISCUSSION_HEADER", 'background-color: #D1DCFF; font-family: helvetica;
       font-size: 140%; border-top: 1px solid #262c40; padding: 2px');
define("MESSAGE_HEADER", 'background-color: #E6ECFF; font-family: helvetica;
       border-top: 1px solid #262c40; padding: 2px');
define("DIGEST_TIME_PERIOD", 86400); // One day currently.
// TODO -- make email from list address + add add list-ids so email client filters still work
# boostrap drupal
# set up the drupal directory -- very important 
$DRUPAL_DIR = '/var/www/island_prod';
require_once('og_mailinglist_utilities.inc');
require_once('og_mailinglist_api.inc');

# set some server variables so Drupal doesn't freak out
$_SERVER['SCRIPT_NAME'] = '/script.php';
$_SERVER['SCRIPT_FILENAME'] = '/script.php';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';

# act as the first user
global $user;
$user->uid = 1;

# gain access to drupal
chdir($DRUPAL_DIR);  # drupal assumes this is the root dir
error_reporting(E_ERROR | E_PARSE); # drupal throws a few warnings, so suppress these
require_once('./includes/bootstrap.inc');
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

# restore error reporting to its normal setting
error_reporting(E_ALL);

// Get list of groups where at least one person has subscribed to a digest node
// and which had a new post or comment in the last 24 hours.

$new_nodes_sql = 'SELECT DISTINCT s.sid
          FROM {og_mailinglist_subscription} s
          JOIN {og_ancestry} o
          JOIN {node} n
          WHERE s.sid = o.group_nid
          AND o.nid = n.nid
          AND n.created > (unix_timestamp() - %d)
          AND s.subscription_type = "digest email"';
          
$new_comments_sql = 'SELECT DISTINCT s.sid
          FROM {og_mailinglist_subscription} s
          JOIN {og_ancestry} o
          JOIN {comments} c
          WHERE s.sid = o.group_nid
          AND o.nid = c.nid
          AND c.timestamp > (unix_timestamp() - %d)
          AND s.subscription_type = "digest email"';

$groups_with_new_nodes = db_query($new_nodes_sql, DIGEST_TIME_PERIOD);
$groups_with_new_comments = db_query($new_comments_sql, DIGEST_TIME_PERIOD);

$digest_groups = array();
while ($data = db_fetch_array($groups_with_new_comments)) {
  $digest_groups[$data['sid']] = $data['sid'];
}
while ($data = db_fetch_array($groups_with_new_nodes)) {
  $digest_groups[$data['sid']] = $data['sid'];
}

//print_r($digest_groups);
foreach ($digest_groups as $gid) {
  $group = node_load(array('nid' => $gid));
  // Get list of new activity -- new nodes and new comments
  $new_nids = 'SELECT o.nid
                FROM {node} n
                JOIN {og_ancestry} o
                WHERE n.nid = o.nid
                AND n.created > (unix_timestamp() - %d)
                AND o.group_nid = %d';
  
  $new_comment_nids = 'SELECT c.nid, c.cid
                        FROM {comments} c
                        JOIN {og_ancestry} o
                        WHERE c.nid = o.nid
                        AND c.timestamp > (unix_timestamp() - %d)
                        AND o.group_nid = %d';

  $nids_with_new_nodes = db_query($new_nids, DIGEST_TIME_PERIOD, $gid);
  $nids_with_new_comments = db_query($new_comment_nids, DIGEST_TIME_PERIOD, $gid);

  $nids = array();
  while ($data = db_fetch_array($nids_with_new_comments)) {
    $nids[$data['nid']]['status'] = "old";
    $nids[$data['nid']]['node_obj'] = node_load(array("nid" => $data['nid']));
    $nids[$data['nid']][$data['cid']] = _comment_load($data['cid']);
  }
  while ($data = db_fetch_array($nids_with_new_nodes)) {
    $nids[$data['nid']]['status'] = "new";
    $nids[$data['nid']]['node_obj'] = node_load(array("nid" => $data['nid']));
  }
  
  
  
  // Count # of messages.
  $message_count = 0;
  foreach ($nids as $nid) {
    $message_count += og_mailinglist_count_new_messages($nid);
  }
  
  $purl_id = db_result(db_query("SELECT value
                                      FROM {purl}
                                      WHERE provider = 'spaces_og'
                                      AND id = %d", $gid));
  
  $subject = "Digest for " . $purl_id . "@" . variable_get("og_mailinglist_server_string", "example.com")
        . " - " . $message_count . " Messages in " . count($nids) . " Discussions";
  
  // Assemble message
  $body = "";
  $body .= "<div style=\"" . EMAIL_HEADER . "\">Today's Discussion Summary</div>\n";
  $body .= "Group: " . url("node/" . $gid, array('absolute' => TRUE)) . "\n";
  $body .= "<ul>\n";
  foreach ($nids as $nid) {
    $body .= "<li>" . l($nid['node_obj']->title, "",
            array('fragment' => "digest-" . $nid['node_obj']->nid,
            'external' => true))
            . " (" . og_mailinglist_count_new_messages($nid) . " New)</li>\n";
  }
  $body .= "</ul>\n";
  $body .= "<hr />\n";

  // Add individual discussions
  foreach ($nids as $nid) {
    $body .= '<a name="digest-' . $nid['node_obj']->nid . '"></a>';
    $body .= '<div style="' . DISCUSSION_HEADER . '">';
    $body .= "Discussion: " . l($nid['node_obj']->title, "node/" .
        $nid['node_obj']->nid, array('absolute' => TRUE)) . "\n";
    $body .= "</div>";
    $body .= "<blockquote>\n";
    // If new node created today.
    if ($nid['status'] === "new") {
      $body .= og_mailinglist_style_node_message($nid['node_obj']);
    }
    
    foreach ($nid as $cid => $comment) {
      if (is_numeric($cid)) {
        $body .= og_mailinglist_style_comment_message($comment);
      }
    }
    $body .= "</blockquote>\n";
  }
  
  // Add footer.
  $body .= "________________________________<br />";
  $body .= "You received this message because you are a member of the \"" .
            $group->title . "\" group on " .
            variable_get("og_mailinglist_server_string", "example.com") . "<br />";
  $body .= "To unsubscribe to this group, visit " .
            url("og_mailinglist/subscriptions", array("absolute" => true)) . "<br />";
  $body .= "To post a new message to this group, email " . $purl_id . "@" .
    variable_get("og_mailinglist_server_string", "example.com") . "<br />";
 
  // For each person, send out an email.
  $result = db_query("SELECT uid
                     FROM {og_mailinglist_subscription}
                     WHERE sid = %d
                     AND subscription_type = \"digest email\"", $gid);
  $uids = array();
  while ($data = db_fetch_array($result)) {
    $email = db_result(db_query("SELECT mail
                                FROM {users}
                                WHERE uid = %d", $data['uid']));
    $uids[$data['uid']] = $email;
  }
  
  // Add kyle be default for awhile to test:
  $uids['3'] = "mathews.kyle@gmail.com";
  
  foreach ($uids as $email) {
    $mailer = og_mailinglist_create_mailer();
    $mailer->From = "no_reply@" . variable_get("og_mailinglist_server_string", "example.com");
    $mailer->FromName = $purl_id . "@" .
    variable_get("og_mailinglist_server_string", "example.com");
    $mailer->AddAddress($email);
    $mailer->Subject = $subject; 
    $mailer->Body = $body;
    $mailer->isHTML(TRUE);
    $mailer->Send();
    echo "Sent digest email to " . $email . " for group " . $group->title . "\n";
  }
}

function og_mailinglist_style_node_message($node) {
  $user = user_load(array('uid' => $node->uid));
  $body .= "<div style=\"" . MESSAGE_HEADER . "\"><strong>" . $user->realname . "</strong> " . $user->mail . " " .
                date("d M Y — g:ia O", $node->created) . "</div>\n";
  $body .= og_mailinglist_prepare_web_content($node->body); // TODO -- node_view and comment_view don't work -- they add too much junk. Just want body.
  $body .= "<br />\n"; // Plus need realname + need to convert dates into something readable + add num new messages per discussion
  
  return $body;
}

function og_mailinglist_style_comment_message($comment) {
  $user = user_load(array('uid' => $comment->uid));
  $body .= "<div style=\"" . MESSAGE_HEADER . "\"><strong>" . $user->realname . "</strong> " . $user->mail . " " .
              date("d M Y — g:ia O", $comment->timestamp) . "</div>\n";
  $body .= og_mailinglist_prepare_web_content($comment->comment);
  $body .= "<br />\n";
  
  return $body;
}

function og_mailinglist_count_new_messages($message) {
    $count = 0;
    if ($message['status'] === "new") {
      $count++;
    }
    $count += count(array_keys($message)) - 2;
    
    return $count;
}
