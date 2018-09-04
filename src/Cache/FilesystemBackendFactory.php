<?php

namespace Drupal\filecache\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;

/**
 * Factory for creating FilesystemBackend cache backends.
 */
class FilesystemBackendFactory implements CacheFactoryInterface {

  /**
   * The service for interacting with the filesystem.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $filesystem;

  /**
   * The environment specific settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The cache tags checksum provider.
   *
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  protected $checksumProvider;

  /**
   * Constructs a FilesystemBackendFactory object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $filesystem
   *   The service for interacting with the filesystem.
   * @param \Drupal\Core\Site\Settings $settings
   *   The environment specific settings.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksumProvider
   *   The cache tags checksum provider.
   */
  public function __construct(FileSystemInterface $filesystem, Settings $settings, TimeInterface $time, CacheTagsChecksumInterface $checksumProvider) {
    $this->filesystem = $filesystem;
    $this->settings = $settings;
    $this->time = $time;
    $this->checksumProvider = $checksumProvider;
  }

  /**
   * Returns the FilesystemBackend for the specified cache bin.
   *
   * @param string $bin
   *   The cache bin for which the object is created.
   *
   * @return \Drupal\filecache\Cache\FilesystemBackend
   *   The cache backend object for the specified cache bin.
   *
   * @throws \Exception
   *   Thrown when no path has been configured to store the files for the given
   *   bin.
   */
  public function get($bin) {
    $path = $this->getPathForBin($bin);
    return new FilesystemBackend($this->filesystem, $this->time, $this->checksumProvider, $path);
  }

  /**
   * Returns the path for the specified cache bin.
   *
   * @param string $bin
   *   The cache bin for which to return the path.
   *
   * @return string
   *   The path or URI to the folder where the cache files for the given bin
   *   will be stored.
   *
   * @throws \Exception
   *   Thrown when no path has been configured.
   */
  protected function getPathForBin($bin) {
    $path_settings = $this->settings->get('filecache_directory');
    // Look for a cache bin specific setting.
    if (isset($path_settings['bins'][$bin])) {
      $path = $path_settings['bins'][$bin];
    }
    // Fall back to the default path.
    elseif (isset($path_settings['default'])) {
      $path = rtrim($path_settings['default'], '/') . '/' . $bin;
    }
    else {
      throw new \Exception('No path has been configured for the filesystem cache backend.');
    }
    return $path;
  }

}
