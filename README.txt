# CF File Access

This plugin allows file requests to be redirected to wordpress so that operations can be performed before delivering files. 
This allows us to perform fun things like authentication and on the fly file generation.

## .htaccess
Files should be redirected here via a .htaccess rewrite. For example, if you want to redirect pdf files here use:

- **On WPMU**  
	`RewriteRule ^(.*/)?files/(.*).pdf index.php [L]`
- **On WordPress**  
	`RewriteRule ^(.*/)?uploads/(.*).pdf index.php [L]`

That tells Apache to send anything with `/files/` (or `/uploads/`) in the url (`/files/` is WordPress MU's way of controlling file distribution) that has `pdf` at the end to redirect to `index.php` where we can get at it. Critical to this statement is the `[L]` modifier at the end - it tells apache that if this is a match that it should NOT try to do any more matching and continue on with the request. Without this other rewrite rules will override our desired results.

## Filter: `cfap_deliver_file`

Inspecting the requested file is done via the `cfap_deliver_file` action. When called it passes in 2 variables: a boolean value of wether to deliver the file and the path of the requested file (most likely something like: `files/2008/10/my_file.pdf`). This allows other plugins to determine wether the file should be shown or not. The filtering function should return false if access is denied, or true if access is granted. 

## Filter: `cfap_filepath`

A way of modifying the file path before the file is delivered. This contains the full filesystem path to the file that is set to be delivered. Any modification should be a full file path to a readable file.

## Filter: `cfap_denied_post`

Use this filter to control the verbiage output on the access denied page. The post contains the basics needed to display a page in "single" context. Data should be modified or added, but object items should not be removed. For example, to now show a title on the access denied page set the `$post->post_title` to '' instead of unsetting the object member.

## Actions: `cfap_passthru`, `cfap_denied`, `cfap_not_found`

Actions on these functions are passed the `$filepath`. `cfap_not_found` defaults to the wordpress 404 page. `cfap_denied` generates a fake page telling the user access denied. This contents can be overidden through the filer `cfap_denied_post`.

# Tested on
- WPMU 2.6.3
- WP 2.9 beta