<?php
namespace Macopedia\CacheBenchmark\Command;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Tymoteusz Motylewski <t.motylewski@macopedia.pl>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

/**
 * Class CacheBenchmarkCommandController
 * This script started as a port of Colin Mollenhour Magento Cache Backend Benchmark
 * - see https://github.com/colinmollenhour/magento-cache-benchmark
 * Thanks Colin!
 *
 * @package Macopedia\CacheBenchmark\Command
 */
class CacheBenchmarkCommandController extends CommandController {

	/**
	 * @var array $_tags Array of tags to benchmark
	 */
	protected $_tags = array();

	/**
	 * @var int $_maxTagLength The length of the longest cache tag
	 * @see benchmark::_getLongestTagLength()
	 */
	protected $_maxTagLength = 0;

	/**
	 * @var string $_cachePrefix The cache tag and id prefix for this Magento instance
	 * @see benchmark::_getCachePrefix()
	 */
	protected $_cachePrefix = '';

	protected $benchmarkCacheName = 'benchmark_cache';

	public function usageCommand() {
		$message = <<<USAGE
This script will either initialize a new benchmark dataset or run a benchmark.

Usage:  php -f shell/cache-benchmark.php [command] [options]

Commands:
  init [options]        Initialize a new dataset.
  load --name <string>  Load an existing dataset.
  clean                 Flush the cache backend.
  tags                  Benchmark getIdsMatchingTags method.
  ops [options]         Execute a pre-generated set of operations on the existing cache.
  analyze               Analyze the current cache contents
  comp_test             Test compression library performance

'init' options:
  --name <string>       A unique name for this dataset (default to "default")
  --numberOfKeys <num>          Number of cache keys (default to 10000)
  --numberOfTags <num>          Number of cache tags (default to 2000)
  --minTagsPerRecord <num>      The min number of tags to use for each record (default 0)
  --maxTagsPerRecord <num>      The max number of tags to use for each record (default 15)
  --minRecordSize <num>  The smallest size for a record (default 1)
  --minRecordSize <num>  The largest size for a record (default 1024)
  --numberOfClients <num>       The number of clients for multi-threaded testing (defaults to 4)
  --numOps <num>           The number of operations to perform per client (defaults to 100000)
  --seed <num>          The random number generator seed (default random)

'ops' options:
  --name <string>       The dataset to use (from the --name option from init command)
  --client <num>        Client number (0-n where n is --clients option from init command)
  -q|--quiet            Be less verbose.

'comp_test' options:
  --lib <string>        One of snappy, lzf, gzip
USAGE;
		$this->output($message);
	}

	/**
	 * Initialize a dataset to files on disk (not loaded into cache)
	 *
	 * @param int $numberOfKeys 		Number of cache keys (default to 10000)
	 * @param int $numberOfTags 		Number of cache tags (default to 2000)
	 * @param int $minTagsPerRecord 	The min number of tags to use for each record (default 0)
	 * @param int $maxTagsPerRecord 	The max number of tags to use for each record (default 15)
	 * @param int $minRecordSize		The smallest size for a record (default 1)
	 * @param int $maxRecordSize		The largest size for a record (default 1024)
	 * @param int $numberOfClients		The number of clients for multi-threaded testing (defaults to 4)
	 * @param int $numOps				The number of operations per client (defaults to 10000)
	 * @param int $writeChanceFactor	The chance-factor that a key will be overwritten (defaults to 1000)
	 * @param int $cleanChanceFactor	The chance-factor that a tag will be cleaned (defaults to 5000)
	 * @param string $name
	 * @param int $seed
	 */
	public function initDatasetCommand(
		$numberOfKeys = 10000,
		$numberOfTags = 2000,
		$minTagsPerRecord = 0,
		$maxTagsPerRecord = 15,
		$minRecordSize = 1,
		$maxRecordSize = 1024,
		$numberOfClients = 4,
		$numOps = 50000,
		$writeChanceFactor = 1000,
		$cleanChanceFactor = 1000,
		$name = 'default',
		$seed=-1
	) {

		$testDir = $this->_getTestDir() . "/$name";
		if (is_dir($testDir)) {
			array_map('unlink', glob("$testDir/*.*"));
		}
		if (!is_dir($testDir)) {
			mkdir($testDir);
		}

		// Dump command-line
		file_put_contents("$testDir/cli.txt", 'php ' . implode(' ', $_SERVER['argv']));

		if ($seed != -1) {
			mt_srand( (int) $seed);
		}

		$tags = array();
		$lengths = array();
		$reads = array();
		$writes = array();
		$expires = array(false, false, false, false, false, 'rand'); // 1/6th of keys expire

		echo "Generating cache data...\n";
		//	$progressBar = $this->_getProgressBar(0, $numberOfKeys / 100);

		// Generate tags
		$this->_createTagList($numberOfTags);

		$smallestKey = FALSE;
		$largestKey = 0;
		$totalKeyData = 0;

		$leastTags = FALSE;
		$mostTags = 0;
		$totalTags = 0;

		if (!($fp = fopen("$testDir/data.txt", 'w'))) {
			die("Could not open $testDir/data.txt");
		}
		fwrite($fp, "$numberOfKeys\r\n");

		// Generate data
		for ($i = 0; $i < $numberOfKeys; $i++) {
			if ($i % 100 == 0) {
				//	$progressBar->update(($i / 100)+1);
			}
			$key = md5($i);
			$expireAt = $expires[mt_rand(0, count($expires) - 1)];
			if ($expireAt == 'rand') {
				$expireAt = mt_rand(10, 14400);
			}
			$data = array(
				'key' => $key,
				'data' => $this->_getRandomData($minRecordSize, $maxRecordSize),
				'tags' => $this->_getRandomTags($minTagsPerRecord, $maxTagsPerRecord),
				'expires' => $expireAt
			);

			// Store length since data length per key will usually be constant
			$lengths[$key] = strlen($data['data']);
			$tags[$key] = $data['tags'];

			// Some keys are read more frequently
			$popularity = mt_rand(1, 100);
			for ($j = 0; $j < $popularity; $j++) {
				$reads[] = $key;
			}

			// Some keys are written more frequently
			$volatility = mt_rand(1, 100);
			for ($j = 0; $j < $volatility; $j++) {
				$writes[] = $key;
			}

			// Stats
			$dataLen = $lengths[$key];
			if ($smallestKey === FALSE || $dataLen < $smallestKey) $smallestKey = $dataLen;
			if ($dataLen > $largestKey) $largestKey = $dataLen;
			$totalKeyData += $dataLen;
			$tagCount = count($data['tags']);
			if ($leastTags === FALSE || $tagCount < $leastTags) $leastTags = $tagCount;
			if ($tagCount > $mostTags) $mostTags = $tagCount;
			$totalTags += $tagCount;
			fwrite($fp, json_encode($data) . "\r\n");
		}
		fclose($fp);
		//	$progressBar->finish();

		// Dump data
		$averageKey = $totalKeyData / $numberOfKeys;
		$averageTags = $totalTags / $numberOfKeys;
		$description = <<<TEXT
Total Keys: $numberOfKeys
Smallest Key Data: $smallestKey
Largest Key Data: $largestKey
Average Key Data: $averageKey
Total Key Data: $totalKeyData
Total Tags: $numberOfTags
Least Tags/key: $leastTags
Most Tags/key: $mostTags
Average Tags/key: $averageTags
Total Tags/key: $totalTags
TEXT;
		echo "$description\n";
		file_put_contents("$testDir/description.txt", $description);

		echo "Generating operations...\n";
		//	$progressBar = $this->_getProgressBar(0, ($numberOfClients * $numOps) / 1000);

		// Create op lists for each client
		for ($i = 0, $k = 0; $i < $numberOfClients; $i++) {
			$ops = array();
			for ($j = 0; $j < $numOps; $j++) {
				if ($k++ % 1000 === 0) {
					//				$progressBar->update($k / 1000);
				}

				// Clean
				if ($cleanChanceFactor > 0 && mt_rand(0, $cleanChanceFactor) == 0) {
					$index = mt_rand(0, count($this->_tags) - 1);
					$tag = $this->_tags[$index];
					$ops[] = array('clean', $tag);
				} // Write
				else if ($writeChanceFactor > 0 && mt_rand(0, $writeChanceFactor) == 0) {
					$index = mt_rand(0, count($writes) - 1);
					$key = $writes[$index];
					$ops[] = array('write', $key, $lengths[$key], $tags[$key]);
				} // Read
				else {
					$index = mt_rand(0, count($reads) - 1);
					$key = $reads[$index];
					$ops[] = array('read', $key);
				}
			}
			file_put_contents("$testDir/ops-client_$i.json", json_encode($ops));
		}
		//	$progressBar->finish();

		$quiet = $numberOfClients == 1 ? '' : '--quiet';
		$script = <<<BASH
#!/bin/bash
QUIET='--quiet'; if [ -t 1 ]; then QUIET=''; fi
php typo3/cli_dispatch.phpsh extbase cachebenchmark:clean
php typo3/cli_dispatch.phpsh extbase cachebenchmark:load --name '$name' \$QUIET
#php typo3/cli_dispatch.phpsh extbase cachebenchmark:tags
results=$testDir/results.txt
rm -f \$results

clients=0
function runClient() {
  clients=$((clients+1))
  php typo3/cli_dispatch.phpsh extbase cachebenchmark:ops --name '$name' --client \$1 $quiet \$QUIET >> \$results &
}
echo "Benchmarking $numberOfClients concurrent clients, each with $numOps operations..."
start=$(date '+%s')
BASH;
		$script .= "\n";
		for ($i = 0; $i < $numberOfClients; $i++) {
			$script .= "runClient $i\n";
		}
		$script .= <<<BASH
wait
finish=$(date '+%s')
elapsed=$((finish - start))
echo "\$clients concurrent clients completed in \$elapsed seconds"
echo ""
echo "         |   reads|  writes|  cleans"
echo "------------------------------------"
awk '
BEGIN { FS=OFS="|" }
      { print; for (i=2; i<=NF; ++i) sum[i] += \$i; j=NF }
END   {
        printf "------------------------------------\\n";
        printf "ops/sec  ";
        for (i=2; i <= j; ++i) printf "%s%8.2f", OFS, sum[i];
      }
' \$results
echo ""
BASH;
		file_put_contents("$testDir/run.sh", $script);
		chmod("$testDir/run.sh", "+w");

		echo "Completed generation of test data for test '$name'.\n";
		echo "Run your test like so:\n\n";
		echo "  \$ bash $testDir/run.sh\n";
	}


	protected function _getTestDir() {
//		$name = $this->getArg('name') ?: 'default';
//		$baseDir = Mage::getBaseDir('var').'/cachebench';
//		if( ! is_dir($baseDir)) {
//			mkdir($baseDir);
//		}
		$path = PATH_site . 'typo3temp/cachebench';
		if (!is_dir($path)) {
			mkdir($path);
		}
		return $path;
	}

	/**
	 * Create internal list of cache tags.
	 *
	 * @param int $nTags The number of tags to create
	 */
	protected function _createTagList($nTags) {
		$length = strlen('' . $nTags);
		for ($i = 1; $i <= $nTags; $i++) {
			$this->_tags[] = sprintf('TAG_%0' . $length . 'd', $i);
		}
	}

	/**
	 * Get random data for cache
	 *
	 * @param $min
	 * @param $max
	 * @return string
	 */
	protected function _getRandomData($min, $max = NULL) {
		if ($max === NULL) {
			$length = $min;
		} else {
			$length = mt_rand($min, $max);
		}
		$string = md5(mt_rand());
		while (strlen($string) < $length) {
			$string = base64_encode($string);
		}
		return substr($string, 0, $length);
	}

	/**
	 * Return a random number of cache tags between the given range.
	 *
	 * @param int $min
	 * @param int $max
	 * @return array
	 */
	protected function _getRandomTags($min, $max) {
		$tags = array();
		$num = mt_rand($min, $max);
		$keys = array_rand($this->_tags, $num);
		foreach ($keys as $i) {
			$tags[] = $this->_tags[$i];
		}
		return $tags;
	}

	public function cleanCommand()
	{
		$this->getCacheBackend()->flush();
	}

	/**
	 * @param string $name
	 * @param bool $quiet
	 */
	public function loadCommand($name='default', $quiet=TRUE)
	{
		$this->_echoCacheConfig();
		$this->_loadDataset($name, $quiet);
	}

	/**
	 * Load the specified dataset
	 *
	 * @param string $name
	 * @param bool $quiet
	 * @throws \RuntimeException
	 */
	protected function _loadDataset($name='default', $quiet=TRUE) {

		$testDir = $this->_getTestDir(). "/$name";
		$dataFile = "$testDir/data.txt";
		if (!file_exists($dataFile)) {
			throw new \RuntimeException("The '$name' test data does not exist. Please run the 'init' command.");
		}
		if (!($fp = fopen($dataFile, 'r'))) {
			throw new \RuntimeException("Could not open $dataFile");
		}
		echo "Loading $name test data..., cache name: $this->benchmarkCacheName\n";
		$numRecords = rtrim(fgets($fp), "\r\n");
//		if (!$quiet) $progressBar = $this->_getProgressBar(0, $numRecords / 100);
		$i = 0;
		$start = microtime(true);
		$size = 0;
		$_elapsed = 0.;
		while ($line = fgets($fp)) {
			$cache = json_decode($line, true);
			if (!$quiet && $i % 100 == 0) {
//				$progressBar->update($i / 100);
			}
			$i++;
			$size += strlen($cache['data']);
			$_start = microtime(true);
			$this->saveCache($cache['data'], $cache['key'], $cache['tags'], $cache['expires']);
			$_elapsed += microtime(true) - $_start;
		}
		fclose($fp);
		$elapsed = microtime(true) - $start;
//		if (!$quiet) $progressBar->finish();
		printf("Loaded %d cache records in %.2f seconds (%.4f seconds cache time). Data size is %.1fK\n", $i, $elapsed, $_elapsed, $size / 1024);
	}



	/**
	 * Since many operations can take quite a long time for large cache pools,
	 * this might help ease the waiting time with a nice console progress bar.
	 *
	 * @param $min
	 * @param $max
	 * @return Zend_ProgressBar A fresh Zend_ProgressBar instance
	 */
	protected function _getProgressBar($min, $max) {
//		$progressBar = new Zend_ProgressBar(new Zend_ProgressBar_Adapter_Console(), $min, $max);
//		$progressBar->getAdapter()->setFinishAction(Zend_ProgressBar_Adapter_Console::FINISH_ACTION_CLEAR_LINE);
//		return $progressBar;
	}

	/**
	 * Run the tags benchmark on the existing dataset
	 *
	 * @param bool $verbose
	 * @throws \OutOfRangeException
	 */
	public function tagsCommand($verbose=FALSE) {
		echo "Analyzing current cache contents...\n";
		$start = microtime(true);

		$nTags = $this->_readTags();
		if (1 > $nTags) {
			throw new \OutOfRangeException('No cache tags found in cache');
		}
		$nEntries = $this->_countCacheRecords();
		if (1 > $nEntries) {
			throw new \OutOfRangeException('No cache records found in cache');
		}

		$time = microtime(true) - $start;
		printf("Counted %d cache IDs and %d cache tags in %.4f seconds\n", $nEntries, $nTags, $time);

		printf("Benchmarking getIdsMatchingTags...\n");
		$times = $this->_benchmarkByTag($verbose);

		$this->_echoAverage($times);
	}

	/**
	 * Read in list of cache tags. Remove the cache prefix to get the tags
	 * specified to Mage_Core_Model_App::saveCache()
	 *
	 * @return int The number of tags
	 */
	protected function _readTags() {
		$this->_tags = array();
		$prefix = $this->_getCachePrefix();
		$tags = (array)Mage::app()->getCache()->getTags();
		$prefixLen = strlen($prefix);
		foreach ($tags as $tag) {
			$tag = substr($tag, $prefixLen);

			// since all records saved through Magento are associated with the
			// MAGE cache tag it is not representative for benchmarking.
			if ('MAGE' === $tag) continue;

			$this->_tags[] = $tag;
		}
		sort($this->_tags);
		return count($this->_tags);
	}

	/**
	 * Return the configured cache prefix according to the logic in core/cache.
	 *
	 * @return string The used cache prefix
	 * @see Mage_Core_Model_Cache::__construct()
	 */
	protected function _getCachePrefix() {
//		if (!$this->_cachePrefix) {
//			$options = Mage::getConfig()->getNode('global/cache');
//			$prefix = '';
//			if ($options) {
//				$options = $options->asArray();
//				if (isset($options['id_prefix'])) {
//					$prefix = $options['id_prefix'];
//				} elseif (isset($options['prefix'])) {
//					$prefix = $options['prefix'];
//				}
//			}
//			if ('' === $prefix) {
//				$prefix = substr(md5(Mage::getConfig()->getOptions()->getEtcDir()), 0, 3) . '_';;
//			}
//			$this->_cachePrefix = $prefix;
//		}
		return $this->_cachePrefix;
	}

	/**
	 * Return the current number of cache records.
	 *
	 * @return int The current number of cache records
	 */
	protected function _countCacheRecords() {
		$ids = $this->getCacheBackend()->getIds();
		return count($ids);
	}


	/**
	 * Get the time used for calling getIdsMatchingTags() for every cache tag in
	 * the property $_tags.
	 * If $verbose is set to true, display detailed statistics for each tag,
	 * otherwise display a progress bar.
	 *
	 * @param bool $verbose If true output statistics for every cache tag
	 * @return array Return an array of timing statistics
	 */
	protected function _benchmarkByTag($verbose = false) {
		$times = array();

		if (!$verbose) {
			//	$progressBar = $this->_getProgressBar(0, count($this->_tags) / 10);
			$counter = 0;
		}

		foreach ($this->_tags as $tag) {
			$start = microtime(true);
			$ids = $this->getCacheBackend()->findIdentifiersByTag($tag);
			$end = microtime(true);
			$times[$tag] = array('time' => $end - $start, 'count' => count($ids));
			if (!$verbose && $counter++ % 10 == 0) {
				//	$progressBar->update($counter / 10);
			}
		}
		if (!$verbose) {
			//	$progressBar->finish();
		}
		return $times;
	}

	/**
	 * Display average values from given tag benchmark times.
	 *
	 * @param array $times
	 */
	protected function _echoAverage(array $times) {
		$totalTime = $totalIdCount = 0;
		$numTags = count($times);
		foreach ($times as $time) {
			$totalTime += $time['time'];
			$totalIdCount += $time['count'];
		}
		printf("Average: %.5f seconds (%5.2f ids per tag)\n", $totalTime / $numTags, $totalIdCount / $numTags);
	}

	/**
	 * Run the ops benchmark from the specified dataset
	 * @param string $name
	 * @param int $client
	 * @param bool $quiet
	 * @throws \RuntimeException
	 */
	public function opsCommand($name = 'default', $client = 0, $quiet = FALSE) {
		$testDir = $this->_getTestDir() . "/$name";
		$dataFile = "$testDir/ops-client_$client.json";
		if (!file_exists($dataFile)) {
			throw new \RuntimeException("The '$name' test data does not exist. Please run the 'init' command.");
		}
		if (!$quiet) echo "Loading operations...\n";
		$data = json_decode(file_get_contents($dataFile), true);
		if (!$quiet) {
			echo "Executing operations...\n";
			//  $progressBar = $this->_getProgressBar(0, count($data) / 100);
		}
		$times = array(
			'read_time' => 0,
			'reads' => 0,
			'write_time' => 0,
			'writes' => 0,
			'clean_time' => 0,
			'cleans' => 0,
		);
		foreach ($data as $i => $op) {
			switch ($op[0]) {
				case 'read':
					$start = microtime(TRUE);
					$this->loadCache($op[1]);
					$elapsed = microtime(TRUE) - $start;
					break;
				case 'write':
					$string = $this->_getRandomData($op[2]);
					$start = microtime(TRUE);
					$this->saveCache($string, $op[1], $op[3]);
					$elapsed = microtime(TRUE) - $start;
					break;
				case 'clean':
					$start = microtime(TRUE);
					$this->cleanCache($op[1]);
					$elapsed = microtime(TRUE) - $start;
					break;
				default:
					throw new \RuntimeException('Invalid op: ' . $op[0]);
			}
			$times[$op[0] . '_time'] += $elapsed;
			$times[$op[0] . 's'] += 1;
			if (!$quiet && ($i % 100 == 0)) {
				//     $progressBar->update($i / 100);
			}
		}
		if (!$quiet) {
			//   $progressBar->finish();
		}

		printf("Client %2d", $client);
		foreach (array('read', 'write', 'clean') as $op) {
			printf("|%8.2f", $times[$op . 's'] / $times[$op . '_time']);
		}
		echo "\n";
	}

	/**
	 * @param $key
	 * @return mixed
	 */
	protected function loadCache($key) {
		$result = $this->getCacheBackend()->get($key);
		return $result;
	}

	/**
	 * @param $tag
	 * @return bool
	 */
	protected function cleanCache($tag) {
		return $this->getCacheBackend()->flushByTag($tag);
	}

	/**
	 * @param $id
	 * @return bool
	 */
	protected function removeCache($id) {
		return $this->getCacheBackend()->remove($id);
	}


	/**
	 * @param $data
	 * @param $key
	 * @param $tags
	 * @param int $expires
	 */
	protected function saveCache($data, $key, $tags, $expires=0) {
		$this->getCacheBackend()->set($key, $data, $tags, $expires);
	}

	/**
	 * Display the configured cache backend(s).
	 */
	protected function _echoCacheConfig() {
		$configArray = array(
			$this->benchmarkCacheName => $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$this->benchmarkCacheName]
		);
		$output = "\nCache Configuration: \n";
		$output .= var_export($configArray, true) . "\n";
		$this->output($output);
	}

	/**
	 * @return \TYPO3\CMS\Core\Cache\Backend\BackendInterface
	 */
	protected function getCacheBackend() {
		return $GLOBALS['typo3CacheManager']->getCache($this->benchmarkCacheName);
	}

	public function analyzeCommand() {
		$backend = $this->getCacheBackend();

		$totalSize = 0;
		$totalKeyTags = 0;
		$totalKeysTag = 0;
		$sizeBuckets = array();
		$tagsBuckets = array();
		$keysBuckets = array();
		$ids = $backend->getIds();
		$totalIds = count($ids);
		foreach ($ids as $id) {
			$data = $backend->load($id);
			$meta = $backend->getMetadatas($id);
			$size = strlen($data);
			$totalSize += $size;
			$sizeBucket = (int)floor($size / 2048);
			$sizeBuckets[$sizeBucket]++;
			$totalKeyTags += count($meta['tags']);
			$tagsBucket = (int)floor(count($meta['tags']) / 1);
			$tagsBuckets[$tagsBucket]++;
		}
		$ids = NULL;
		$avgSize = round($totalSize / $totalIds / 1024, 2);
		$totalSize = round($totalSize / (1024 * 1024), 2);
		$avgTags = $totalKeyTags / $totalIds;
		$tags = $backend->getTags();
		$totalTags = count($tags);
		foreach ($tags as $tag) {
			$tagCount = count($backend->getIdsMatchingAnyTags(array($tag)));
			$keysBucket = (int)floor($tagCount / 1);
			$keysBuckets[$keysBucket]++;
			$totalKeysTag += $tagCount;
		}
		$tags = NULL;
		$avgKeys = round($totalKeysTag / $totalTags, 2);
		echo
		"Total Ids\t$totalIds
Total Size\t{$totalSize} Mb
Total Tags\t$totalTags
Average Size\t$avgSize Kb
Average Tags/Key\t$avgTags
Average Keys/Tag\t$avgKeys
";
		ksort($sizeBuckets);
		echo "Under Kb\tCount\n";
		foreach ($sizeBuckets as $size => $count) {
			$size *= 2;
			echo "{$size}Kb\t$count\n";
		}
		ksort($tagsBuckets);
		echo "Tags/Key\tCount\n";
		foreach ($tagsBuckets as $tags => $count) {
			$tags *= 1;
			echo "{$tags} tags\t$count\n";
		}
		echo "Keys/Tag\tCount\n";
		ksort($keysBuckets);
		foreach ($keysBuckets as $keys => $count) {
			$keys *= 1;
			echo "{$keys} keys\t$count\n";
		}
	}

	/**
	 * @param string $lib
	 */
	public function compTestCommand($lib) {
		$numChunks = 10240;
		$chunks = array();
		for ($i = 0; $i < $numChunks; $i++) {
			$chunks[] = '016_' . sha1(mt_rand(0, $numChunks * 10));
		}

		switch ($lib) {
			case 'snappy':
				$compress = 'snappy_compress';
				$decompress = 'snappy_uncompress';
				break;
			case 'lzf':
				$compress = 'lzf_compress';
				$decompress = 'lzf_decompress';
				break;
			case 'gzip':
				$compress = array($this, 'gzcompress');
				$decompress = 'gzuncompress';
				break;
			default:
				die("Please specify a lib with --lib. Options are: snappy, lzf, gzip\n");
		}
		if (!function_exists($decompress)) {
			echo "The function $decompress does not exist.\n";
			exit;
		}

		echo "Testing compression with {$lib}\n";
		$len = 0;
		$zlen = 0;
		$compressTime = 0;
		$decompressTime = 0;
		for ($i = 0; $i < $numChunks; $i = ($i >= 1024 ? $i + 4096 : $i * 3)) {
			$string = implode(',', array_slice($chunks, 0, $i));
			$start = microtime(true);
			$zstring = call_user_func($compress, $string);
			$encode = microtime(true) - $start;
			$start = microtime(true);
			$ungztags = call_user_func($decompress, $zstring);
			$decode = microtime(true) - $start;
			$len += strlen($string);
			$zlen += strlen($zstring);
			$compressTime += $encode;
			$decompressTime += $decode;
			printf("random from %d bytes to %d bytes (%2.4f%%) in %.6f seconds / %.6f seconds\n", strlen($string), strlen($zstring), ((strlen($string) - strlen($zstring)) / strlen($string)) * 100., $encode, $decode);
			if ($ungztags != $string) echo "ERROR\n";
			if (!$i) $i = 1;
		}
		foreach (array(
					 './app/code/core/Mage/Core/etc/system.xml',
					 './app/code/core/Mage/Catalog/etc/system.xml',
					 './app/code/core/Mage/Sales/etc/system.xml',
					 './app/code/core/Mage/Core/etc/config.xml',
					 './app/code/core/Mage/Catalog/etc/config.xml',
					 './app/code/core/Mage/Sales/etc/config.xml',
					 './app/design/frontend/base/default/template/catalog/product/view.phtml',
					 './app/design/frontend/base/default/template/catalog/product/list.phtml',
					 './app/design/frontend/base/default/template/page/3columns.phtml',
				 ) as $xmlFile) {
			$string = file_get_contents($xmlFile);
			$start = microtime(true);
			$zstring = call_user_func($compress, $string);
			$encode = microtime(true) - $start;
			$start = microtime(true);
			$ungztags = call_user_func($decompress, $zstring);
			$decode = microtime(true) - $start;
			$len += strlen($string);
			$zlen += strlen($zstring);
			$compressTime += $encode;
			$decompressTime += $decode;
			printf("%s from %d bytes to %d bytes (%2.4f%%) in %.6f seconds / %.6f seconds\n", $xmlFile, strlen($string), strlen($zstring), ((strlen($string) - strlen($zstring)) / strlen($string)) * 100., $encode, $decode);
			if ($ungztags != $string) echo "ERROR\n";
		}
		foreach (array('Mage::app()->getConfig()') as $xmlFile) {
			$string = Mage::app()->getConfig()->getNode()->asXML();
			$start = microtime(true);
			$zstring = call_user_func($compress, $string);
			$encode = microtime(true) - $start;
			$start = microtime(true);
			$ungztags = call_user_func($decompress, $zstring);
			$decode = microtime(true) - $start;
			$len += strlen($string);
			$zlen += strlen($zstring);
			$compressTime += $encode;
			$decompressTime += $decode;
			printf("%s from %d bytes to %d bytes (%2.4f%%) in %.6f seconds / %.6f seconds\n", $xmlFile, strlen($string), strlen($zstring), ((strlen($string) - strlen($zstring)) / strlen($string)) * 100., $encode, $decode);
			if ($ungztags != $string) echo "ERROR\n";
		}
		printf("Total: from %d bytes to %d bytes (%2.4f%%) in %.6f seconds / %.6f seconds\n", $len, $zlen, (($len - $zlen) / $len) * 100., $compressTime, $decompressTime);
	}

	protected function gzcompress($data) {
		return gzcompress($data, 1);
	}

	/**
	 * Return the length of the longest tag in the $_tags property.
	 *
	 * @param bool $force If true don't use the cached value
	 * @return int The length of the longest tag
	 */
	protected function _getLongestTagLength($force = false) {
		if (0 === $this->_maxTagLength || $force) {
			$len = 0;
			foreach ($this->_tags as $tag) {
				$tagLen = strlen($tag);
				if ($tagLen > $len) {
					$len = $tagLen;
				}
			}
			$this->_maxTagLength = $len;
		}
		return $this->_maxTagLength;
	}
}