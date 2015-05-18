<?php

namespace OC\Cache;

use OC\BackgroundJob\Job;
use OCP\IConfig;

class FileGlobalGC extends Job {
	// only do cleanup every 5 minutes
	const CLEANUP_TTL_SEC = 300;

	public function run($argument) {
		$this->gc(\OC::$server->getConfig(), $this->getCacheDir());
	}

	protected function getCacheDir() {
		return get_temp_dir() . '/owncloud-' . \OC_Util::getInstanceId() . '/';
	}

	/**
	 * @param string $cacheDir
	 * @param int $now
	 * @return string[]
	 */
	public function getExpiredPaths($cacheDir, $now) {
		$files = scandir($cacheDir);
		$files = array_filter($files, function ($file) {
			return $file != '.' and $file != '..';
		});
		$paths = array_map(function ($file) use ($cacheDir) {
			return $cacheDir . $file;
		}, $files);
		return array_values(array_filter($paths, function ($path) use ($now) {
			return is_file($path) and (filemtime($path) < $now);
		}));
	}

	/**
	 * @param \OCP\IConfig $config
	 * @param string $cacheDir
	 */
	public function gc(IConfig $config, $cacheDir) {
		$lastRun = $config->getAppValue('core', 'global_cache_gc_lastrun', 0);
		$now = time();
		if (($now - $lastRun) < self::CLEANUP_TTL_SEC) {
			return;
		}
		$config->setAppValue('core', 'global_cache_gc_lastrun', $now);
		if (!is_dir($cacheDir)) {
			return;
		}
		$paths = $this->getExpiredPaths($cacheDir, $now);
		array_walk($paths, function($file) {
			unlink($file);
		});
	}
}
