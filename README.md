cache_benchmark
===============

TYPO3 Cache Backend benchmark tool.
This tool was started as a port of magento-cache-benchmark created by Colin Mollenhour. See https://github.com/colinmollenhour/magento-cache-benchmark
Thanks Colin!

### USAGE

    php typo3/cli_dispatch.phpsh extbase cachebenchmark:initdataset
    bash typo3temp/cachebench/default/run.sh


By default it's testing fileCacheBackend used by "benchmark_cache" configured in ext_localconf.php
You can change the cache used by the tool by changing the $benchmarkCacheName property of CacheBenchmarkCommandController class.

## FEATURES

* Flexible dataset generation via options to init command
* Repeatable tests. Dataset is written to static files so the same test can be repeated, even with different backends.
* Test datasets can easily be zipped up and copied to different environments or shared.
* Can easily test multiple pre-generated datasets.
* Supports multi-process benchmarking, each process with a different set of random operations.
* Cache record data size, number of tags, expiration, popularity and volatility are all randomized.

## EXAMPLE RUN

    Loading default test data..., cache name: benchmark_cache2
    Loaded 10000 cache records in 2.86 seconds (2.6558 seconds cache time). Data size is 5015.8K
    Cache Configuration:
    array (
       'benchmark_cache' =>
       array (
         'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\FileBackend',
       ),
     )
    Benchmarking 4 concurrent clients, each with 50000 operations...
    4 concurrent clients completed in 27 seconds

             |   reads|  writes|  cleans
    ------------------------------------
    Client  3|21375.45| 2855.82|    2.53
    Client  1|21803.16| 1603.37|    1.86
    Client  0|21804.63| 2858.39|    1.98
    Client  2|21705.74| 2678.36|    1.85
    ------------------------------------
    ops/sec  |86688.98| 9995.94|    8.22
