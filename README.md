# WP-Ban
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: banned, ban, deny, denied, permission, ip, hostname, host, spam, bots, bot, exclude, referrer, url, referral, range  
Requires at least: 4.3   
Tested up to: 5.9  
Stable tag: 1.69  

Ban users by IP, IP Range, host name, user agent and referrer url from visiting your WordPress's blog.

## Description
It will display a custom ban message when the banned IP, IP range, host name or referrer url that tries to visit you blog. You can also exclude certain IPs from being banned. There will be statistics recorded on how many times they attempt to visit your blog. It allows wildcard matching too.

### Build Status
[![Build Status](https://travis-ci.org/lesterchan/wp-ban.svg?branch=master)](https://travis-ci.org/lesterchan/wp-ban)

### Development
* [https://github.com/lesterchan/wp-ban](https://github.com/lesterchan/wp-ban "https://github.com/lesterchan/wp-ban")

### Translations
* [http://dev.wp-plugins.org/browser/wp-ban/i18n/](http://dev.wp-plugins.org/browser/wp-ban/i18n/ "http://dev.wp-plugins.org/browser/wp-ban/i18n/")

### Credits
* Plugin icon by [Dave Gandy](http://fontawesome.io) from [Flaticon](http://www.flaticon.com)

### Donations
* I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Changelog
### Version 1.69
* NEW: Bump WordPress 4.7
* FIXED: Notices

### Version 1.68
* NEW: Use translate.wordpress.org to translate the plugin
* NEW: Use HTML DOCTYPE
* FIXED: Remove get_language_attributes()

### Version 1.67
* FIXED: Notices

### Version 1.66
* FIXED: Cannot redeclare get_language_attributes()

### Version 1.65
* NEW: Supports WordPress Multisite Network Activation
* NEW: Uses native WordPress uninstall.php 

### Version 1.64
* NEW: Added a new ban option 'reverse proxy' to allow user to choose whether to check against HTTP_X_FORWARDED_FOR header for IP. Props Tom Adams at dxw. This fixes [CVE-2014-6230](https://security.dxw.com/advisories/vulnerability-in-wp-ban-allows-visitors-to-bypass-the-ip-blacklist-in-some-configurations/)

### Version 1.63
* FIXED: Notices

### Version 1.62 (12-03-2013)
* FIXED: Use a different modifier for preg_match() and use preg_quote() to escape regex

### Version 1.61 (11-03-2013)
* FIXED: Replace ereg() with preg_match()

### Version 1.60 (23-05-2012)
* NEW: AJAX Preview Of Current Banned Message
* NEW: Added nonce To Form
* FIXED: Don't Process Ban If Any Of The Conditions Are Empty

### Version 1.50 (01-06-2009)
* NEW: Added "Your User Agent" Details
* NEW: Uses jQuery Framework
* FIXED: Uses $_SERVER['PHP_SELF'] With plugin_basename(__FILE__) Instead Of Just $_SERVER['REQUEST_URI']

### Version 1.40 (12-12-2008)
* NEW: Works With WordPress 2.7 Only
* NEW: Changed Ban Admin Setting Location To 'WP-Admin -> Settings -> Ban'
* NEW: Right To Left Language Support by Kambiz R. Khojasteh
* NEW: Called ban_textdomain() In ban_init() by Kambiz R. Khojasteh
* NEW: Use language_attributes() To Get Attributes Of HTML Tag For Default Template by Kambiz R. Khojasteh

### Version 1.31 (16-07-2008)
* NEW: Works With WordPress 2.6
* FIXED: Do Not Ban If IP is "unknown"

### Version 1.30 (01-06-2008)
* NEW: Uses /wp-ban/ Folder Instead Of /ban/
* NEW: Uses wp-ban.php Instead Of ban.php
* NEW: Uses number_format_i18n()
* NEW: IPs Listed In Ban Stats Is Now Sorted Numerically
* NEW: Banned By User Agents (By: Jorge Garcia de Bustos)
* FIXED: "unknown" IPs (By: Jorge Garcia de Bustos)

### Version 1.20 (01-10-2007)
* NEW: Ability To Uninstall WP-Ban
* NEW: Moved Ban Options From ban.php To ban-options.php

### Version 1.11 (01-06-2007
* NEW: Banned By Referer URL
* NEW: Ability To Exclude Specific IPs From Being Banned
* NEW: Added Template Variables For User Attempts Count And Total Attempts Count
* FIXED: Suppress gethostbyaddr() Error

### Version 1.10 (01-02-2007)
* NEW: Works For WordPress 2.1 Only
* NEW: Move ban.php To ban Folder
* NEW: Localize WP-Ban
* NEW: Added Ban Attempts Statistics In 'WP-Admin -> Manage -> Ban'
* NEW: Move Ban Tab To 'WP-Admin -> Manage'
* NEW: Added Toggle All Checkboxes
* FIXED: Main Administrator Of The Site Cannot Be Banned

### Version 1.00 (02-01-2007)
* NEW: Initial Release

## Installation

1. Open `wp-content/plugins` Folder
2. Put: `Folder: wp-ban`
3. Activate `WP-Ban` Plugin
4. Go to `WP-Admin -> Settings -> Ban` to configure the plugin

## Upgrading

1. Deactivate `WP-Ban` Plugin
2. Open `wp-content/plugins` Folder
3. Put/Overwrite: `Folder: wp-ban`
4. Activate `WP-Ban` Plugin

## Upgrade Notice

N/A

## Screenshots

1. Admin - Ban
2. Admin - Ban
3. Admin - Ban
4. Ban - Message

## Frequently Asked Questions

N/A
