<?php

namespace Drupal\filecache\Cache;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * A cache backend that stores cache items as files on the filesystem.
 */
class FilesystemBackend implements CacheBackendInterface {

  /**
   * The service for interacting with the filesystem.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $filesystem;

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
   * The path or streamwrapper URI to the cache files folder.
   *
   * @var string
   */
  protected $path;

  /**
   * Constructs a FileBackend cache backend.
   *
   * @param \Drupal\Core\File\FileSystemInterface $filesystem
   *   The service for interacting with the filesystem.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksumProvider
   *   The cache tags checksum provider.
   * @param string $path
   *   The path or streamwrapper URI to the folder where the cache files are
   *   stored.
   */
  public function __construct(FileSystemInterface $filesystem, TimeInterface $time, CacheTagsChecksumInterface $checksumProvider, $path) {
    $this->filesystem = $filesystem;
    $this->time = $time;
    $this->checksumProvider = $checksumProvider;
    $this->path = rtrim($path, '/') . '/';
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $filename = $this->getFilename($cid);
    if ($item = $this->getFile($filename)) {
      $item = $this->prepareItem($item, $allow_invalid);
      if (!empty($item)) {
        return $item;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $items = [];
    foreach ($cids as $key => $cid) {
      if ($item = $this->get($cid, $allow_invalid)) {
        $items[$cid] = $item;
        // According to the method documentation the existing cache IDs should
        // be removed from the list of IDs which is passed in by reference.
        unset($cids[$key]);
      }
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->ensureCacheFolderExists();

    $filename = $this->getFilename($cid);

    // Validate cache tags and remove duplicates.
    assert(Inspector::assertAllStrings($tags, 'Cache Tags must be strings.'));
    $tags = array_unique($tags);
    sort($tags);

    $item = (object) [
      'cid' => $cid,
      'data' => $data,
      'expire' => $expire,
      'tags' => $tags,
      'created' => round(microtime(TRUE), 3),
      'checksum' => $this->checksumProvider->getCurrentChecksum($tags),
    ];

    if (file_put_contents($filename, serialize($item)) === FALSE) {
      throw new \Exception('Cache entry could not be created.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    foreach ($items as $cid => $item) {
      // Provide default values.
      $item += [
        'expire' => CacheBackendInterface::CACHE_PERMANENT,
        'tags' => [],
      ];
      $this->set($cid, $item['data'], $item['expire'], $item['tags']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $filename = $this->getFilename($cid);
    if (is_file($filename)) {
      $this->filesystem->unlink($filename);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      $this->delete($cid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->ensureCacheFolderExists();

    $iterator = $this->getFilesystemIterator();
    foreach ($iterator as $filename) {
      // We are dealing with a flat list of files. If there are any folders
      // present these are user managed, skip them.
      if (is_file($filename)) {
        $this->filesystem->unlink($filename);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    if ($item = $this->get($cid)) {
      $item->expire = $this->getRequestTime() - 1;
      $this->set($cid, $item->data, $item->expire, $item->tags);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    foreach ($cids as $cid) {
      $this->invalidate($cid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->ensureCacheFolderExists();

    $iterator = $this->getFilesystemIterator();
    foreach ($iterator as $filename) {
      if (is_file($filename)) {
        $item = $this->getFile($filename);
        $this->invalidateItem($item);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    $this->ensureCacheFolderExists();

    $iterator = $this->getFilesystemIterator();
    foreach ($iterator as $filename) {
      if (is_file($filename)) {
        $item = $this->getFile($filename);
        $this->prepareItem($item, TRUE);
        if (!$item->valid) {
          $this->delete($item->cid);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->deleteAll();

    // Remove the folders if they are empty.
    $iterator = $this->getFilesystemIterator();
    if (!$iterator->valid()) {
      $this->filesystem->rmdir($this->path);
    }
  }

  /**
   * Normalizes a cache ID in order to comply with file naming limitations.
   *
   * There are many different filesystems in use on web servers. In order to
   * maximize compatibility we will use filenames that only include alphanumeric
   * characters, hyphens and underscores with a max length of 255 characters.
   *
   * @param string $cid
   *   The passed in cache ID.
   *
   * @return string
   *   An cache ID consisting of alphanumeric characters, hypens and underscores
   *   with a maximum length of 255 characters.
   */
  protected function normalizeCid($cid) {
    // Nothing to do if the ID is already valid.
    $cid_uses_valid_characters = (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $cid);
    if (strlen($cid) <= 255 && $cid_uses_valid_characters) {
      return $cid;
    }
    // Return a string that uses as much as possible of the original cache ID
    // with the hash appended.
    $hash = Crypt::hashBase64($cid);
    if (!$cid_uses_valid_characters) {
      return $hash;
    }
    return substr($cid, 0, 255 - strlen($hash)) . $hash;
  }

  /**
   * Returns the filename for the given cache ID.
   *
   * @param string $cid
   *   The cache ID.
   *
   * @return string
   *   The filename.
   */
  protected function getFilename($cid) {
    return $this->path . $this->normalizeCid($cid);
  }

  /**
   * Verifies that the cache folder exists and is writable.
   *
   * @throws \Exception
   *   Thrown when the folder could not be created or is not writable.
   */
  protected function ensureCacheFolderExists() {
    if (!is_dir($this->path)) {
      if (!mkdir($this->path, 0755, TRUE)) {
        throw new \Exception('Could not create cache folder ' . $this->path);
      }
    }

    if (!is_writable($this->path)) {
      throw new \Exception('Cache folder ' . $this->path . ' is not writable.');
    }
  }

  /**
   * Prepares a cache item for returning to the cache handler.
   *
   * Checks that items are either permanent or did not expire, and returns data
   * as appropriate.
   *
   * @param object $item
   *   A cache item.
   * @param bool $allow_invalid
   *   (optional) If TRUE, cache items may be returned even if they have expired
   *   or been invalidated.
   *
   * @return object|null
   *   The item with data as appropriate or NULL if there is no valid item to
   *   load.
   */
  protected function prepareItem(\stdClass $item, $allow_invalid) {
    if (!isset($item->data)) {
      return NULL;
    }

    // Check expire time.
    $item->valid = $item->expire == Cache::PERMANENT || $item->expire >= $this->getRequestTime();

    // Check if invalidateTags() has been called with any of the item's tags.
    if (!$this->checksumProvider->isValid($item->checksum, $item->tags)) {
      $item->valid = FALSE;
    }

    if (!$allow_invalid && !$item->valid) {
      return NULL;
    }

    return $item;
  }

  /**
   * Invalidates the given cache item.
   *
   * @param \stdClass $item
   *   The cache item.
   *
   * @throws \Exception
   *   Thrown when the invalidated item cannot be saved.
   */
  protected function invalidateItem(\stdClass $item) {
    $item->expire = $this->getRequestTime() - 1;
    $this->set($item->cid, $item->data, $item->expire, $item->tags);
  }

  /**
   * Returns the raw, unprepared cache item from the given file.
   *
   * @param string $filename
   *   The path or streamwrapper URI of the file to load.
   *
   * @return \stdClass|null
   *   The raw, unprepared cache item or NULL if the file does not exist.
   */
  protected function getFile($filename) {
    if (is_file($filename)) {
      $serialized_contents = file_get_contents($filename);
      if ($serialized_contents !== FALSE) {
        return unserialize($serialized_contents);
      }
    }
    return NULL;
  }

  /**
   * Returns the filesystem iterator for the current cache bin.
   *
   * @return \FilesystemIterator
   *   The iterator.
   */
  protected function getFilesystemIterator() {
    return new \FilesystemIterator($this->path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
  }

  /**
   * Returns the request time as a UNIX timestamp.
   *
   * @return int
   *   The request time as a UNIX timestamp.
   */
  protected function getRequestTime() {
    return $this->time->getRequestTime();
  }

}
