<?php
if (TYPO3_MODE === 'BE') {
	$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'][] = 'Macopedia\\CacheBenchmark\\Command\\CacheBenchmarkCommandController';
}