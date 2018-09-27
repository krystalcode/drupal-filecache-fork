CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Configuration
  * Cache bins
  * Cache directory
 * Uninstalling
 * Troubleshooting
 * Maintainers


INTRODUCTION
============

This module allows Drupal cache bins to be stored in files rather than in the
database.

 * For a full description of the module, visit the project page:
   https://drupal.org/project/filecache

 * To submit bug reports and feature suggestions, or to track changes:
   https://drupal.org/project/issues/filecache


REQUIREMENTS
============

No modules are required but for security reasons File Cache needs to store files
in a private directory outside of the webserver root.


INSTALLATION
============

Install and enable filecache module as usual.


CONFIGURATION
=============

All configuration settings reside in `settings.php` or `settings.local.php`
(preferred).


CACHE BINS
----------

Drupal allows to specify a default cache backend, which will be used for all
cache bins (unless they are overridden). If you want to use file system based
caching by default add the following line to `settings.php` or
`settings.local.php`:

```
$settings['cache']['default'] = 'cache.backend.file_system';
```

You can also override specific cache bins. If for example you want to put the
`entity` and `page` in file storage, but keep using the database backend for all
other bins, you can add the following lines:

```
$settings['cache']['bins']['entity'] = 'cache.backend.file_system';
$settings['cache']['bins']['page'] = 'cache.backend.file_system';
```

For more detailed information, please refer to the
[Cache API](https://api.drupal.org/api/drupal/core%21core.api.php/group/cache/)
documentation.


CACHE DIRECTORY
---------------

As explained in the Requirements section, the cache directory must not be
accessible through the webserver. The configuration setting is named
`filecache_directory` and it allows to configure a default location as well as
individual locations for each cache bin. It is possible to use absolute system
paths (such as `/var/cache/filecache`) or stream wrappers (such as
`private://filecache`).

File Cache will try to create the directories for you. If you manually create
them, make sure that the owner is set to the same user as used by the web
server, and its permissions are set to 700 (owner can do anything, others are
not allowed to access the directory).

The 'default' cache bin is used for all cache bins that are not explicitly
configured. You don't need to provide the name of the cache bin as component in
the pathname, because File Cache will automatically create subdirectories for
each cache bin. Example:

```
$settings['filecache_paths']['default'] = '/var/cache/filecache';
$settings['filecache_paths']['bins']['entity'] = 'private://filecache/entity';
```

To increase performance it is possible to store the cache files on a RAM drive
by using `tmpfs` or similar file systems. Since the cache directories are
automatically created by File Cache, it is usually no problem that these cache
files disappear when the machine is restarted.
Note that some Drupal sites may generate a lot of cache data and a RAM drive
might run out of space. In those cases it would be advised to use a fast solid
state drive to store the cache files.


UNINSTALLING
============

Make sure to remove the File Cache related settings from the `settings.php` file
before uninstalling the module.


TROUBLESHOOTING
===============

You should check Status report page for self-checks by File Cache.

If you use CLI tools such as Drush or Drupal Console, make sure to run the
commands as the webserver user, so that any cache files and directories created
by the commands have the right ownership and permissions.

Examples:

```
$ sudo -u apache ./vendor/bin/drush cache:rebuild
$ sudo -u www-data ./vendor/bin/drupal cache:rebuild
```


MAINTAINERS
===========

* Ognyan Kulev (ogi) - https://www.drupal.org/u/ogi
* Pieter Frenssen (pfrenssen) - https://www.drupal.org/u/pfrenssen
