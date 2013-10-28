<?php
if (TYPO3_MODE === 'BE') {
	$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'][] = 'Macopedia\\CacheBenchmark\\Command\\CacheBenchmarkCommandController';
}

if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache'])) {
	$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache'] = array();
}
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache']['backend'])) {
	$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\FileBackend';
}


if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache2'])) {
	$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache2'] = array();
}
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache2']['backend'])) {
	$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['benchmark_cache2']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\FileBackend';
}
