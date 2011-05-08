<?php

/**
 * Simple SERP Tracker class
 *
 * http://www.andreyvoev.com/simple-serp-tracker-php-class
 *
 * @copyright Andrey Voev 2011
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Andrey Voev <andreyvoev@gmail.com>
 * @version 1.0
 *
 */


    abstract class Tracker
    {
        // the url that we will use as a base for our search
        protected $baseurl;

        // the site that we are searching for
        protected $site;

        // the keywords for the search
        protected $keywords;

        // the current page the crawler is on
        protected $current;

        // starting time of the search
        protected $time_start;

        // debug info array
        protected $debug;

        // the limit of the search results
        protected $limit;

        // proxy file value
        protected $proxy;
        public $found;

       /**
        * Constructor function for all new tracker instances.
        *
        * @param Array $keywords
        * @param String $site
        * @param Int $limit OPTIONAL: number of results to search
        * @return tracker
        */
        function __construct(array $keywords, $site, $limit = 100)
        {
            // the keywords we are searching for
            $this->keywords = $keywords;

            // the url of the site we are checking the position of
            $this->site = $site;

            // set the maximum results we will search trough
            $this->limit = $limit;

            // setup the array for the results
            $this->found = array();

            // starting position
            $this->current = 0;

            // start benchmarking
            $this->time_start = microtime(true);

            // set the time limit of the script execution - default is 6 min.
            set_time_limit(360);

            // check if all the required parameters are set
            $this->initial_check();
        }

       /**
        * Initial check if the base url is a string and if it has the required "keyword" and "position" keywords.
        */
        protected function initial_check()
        {
            // get the model url from the extension class
            $url = $this->set_baseurl();

            // check if the url is a string
            if(!is_string($url)) die("The url must be a string");

            // check if the url has the keyword and parameter in it
            $k = strpos($url, 'keyword');
            $p = strpos($url, 'position');
            if ($k === FALSE || $p === FALSE) die("Missing keyword or position parameter in URL");
        }

       /**
        * Set up the proxy if used
        *
        * @param String $file OPTIONAL: if filename is not provided, the proxy will be turned off.
        */
        public function use_proxy($file = FALSE)
        {
            // the name of the proxy txt file if any
            $this->proxy = $file;

            if($this->proxy != FALSE)
            {
                if(file_exists($this->proxy))
                {
                    // get a proxy from a supplied file
                    $proxies = file($this->proxy);

                    // select a random proxy from the list
                    $this->proxy = $proxies[array_rand($proxies)];
                }
                else
                {
                    die("The proxy file doesn't exist");
                }
            }
        }

       /**
        * Parse the result from the crawler and pass the result html to the find function.
        *
        * @param String $single_url OPTIONAL: override the default url
        * @return String $result;
        */
        protected function parse(array $single_url = NULL)
        {

          // array of curl handles
          $curl_handles = array();
          // data to be returned
          $result = array();

          // multi handle
          $mh = curl_multi_init();

          // check if another URL is supplied
          $urls = ($single_url == NULL) ? $this->baseurl : $single_url;

          // loop through $data and create curl handles and add them to the multi-handle
          foreach ($urls as $id => $d)
          {
                $curl_handles[$id] = curl_init();

                $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
                curl_setopt($curl_handles[$id], CURLOPT_URL,            $url);
                curl_setopt($curl_handles[$id], CURLOPT_HEADER,         0);
                curl_setopt($curl_handles[$id], CURLOPT_RETURNTRANSFER, 1);

                if($this->proxy != FALSE)
                {
                    // use the selected proxy
                    curl_setopt($curl_handles[$id], CURLOPT_HTTPPROXYTUNNEL, 0);
                    curl_setopt($curl_handles[$id], CURLOPT_PROXY, $this->proxy);
                }

                // is it post?
                if (is_array($d))
                {
                  if (!empty($d['post']))
                  {
                    curl_setopt($curl_handles[$id], CURLOPT_POST,       1);
                    curl_setopt($curl_handles[$id], CURLOPT_POSTFIELDS, $d['post']);
                  }
                }

                // are there any extra options?
                if (!empty($options))
                {
                  curl_setopt_array($curl_handles[$id], $options);
                }

                curl_multi_add_handle($mh, $curl_handles[$id]);
            }

            // execute the handles
            $running = null;
            do
            {
                curl_multi_exec($mh, $running);
            }
            while($running > 0);

            // get content and remove handles
            foreach($curl_handles as $id => $c)
            {
                $result[$id] = curl_multi_getcontent($c);
                curl_multi_remove_handle($mh, $c);
            }

            // close curl
            curl_multi_close($mh);

            // return the resulting html
            return $result;
        }

       /**
        * Crawl trough every page and pass the result to the find function until all the keywords are processed.
        */
        protected function crawl()
        {

            $this->setup();
            $html = $this->parse();

            $i = 0;
            foreach($html as $single)
            {
                $result = $this->find($single);

                if($result !== FALSE)
                {

                    if(!isset($this->found[$this->keywords[$i]]))
                    {
                        $this->found[$this->keywords[$i]] = $this->current + $result;

                        // save the time it took to find the result with this keyword
                        $this->debug['time'][$this->keywords[$i]] = number_format(microtime(true) - $this->time_start, 3);

                        unset($this->keywords[$i]);
                    }



                    // remove the keyword from the haystack
                    unset($this->keywords[$i]);
                }
                $i++;
            }

            if(!empty($this->keywords))
            {
                if($this->current <= $this->limit)
                {
                    $this->current += 10;
                    $this->crawl();
                }
            }
        }

       /**
        * Prepare the array of the keywords for every run.
        */
        protected function setup()
        {
            // prepare the url array for the new loop
            unset($this->baseurl);

            foreach($this->keywords as $keyword)
            {
                $url = $this->set_baseurl();
                $url = str_replace("keyword", $keyword, $url);
                $url = str_replace("position", $this->current, $url);
                $this->baseurl[] = $url;
            }
        }

       /**
        * Start the crawl/search process.
        */
        function run()
        {
            $this->crawl();
        }

       /**
        * Return the results from the search.
        *
        * @return Array $this->found
        */
        function get_results()
        {
            return $this->found;
        }

       /**
        * Return the debug information - time taken, etc.
        *
        * @return Array $this->debug
        */
        function get_debug_info()
        {
            return $this->debug;
        }

       /**
        * Set up the base url for the specific search engine using "keyword" and "position" for setting up the template.
        *
        * @return String $baseurl;
        */
        abstract function set_baseurl();

       /**
        * Find the occurrence of the site in the results page. Specific for every search engine.
        *
        * @param String $html OPTIONAL: override the default html if needed
        * @return String $baseurl;
        */
        abstract function find($html);

    }

    class GoogleTracker extends Tracker
    {

        function set_baseurl()
        {
            // use "keyword" and "position" to mark the position of the variables in the url
            $baseurl = "http://www.google.com/search?q=keyword&start=position";
            return $baseurl;
        }

        function find($html)
        {

            // process the html and return either a numeric value of the position of the site in the current page or FALSE
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $nodes = $dom->getElementsByTagName('cite');

            // found is false by default, we will set it to the position of the site in the results if found
            $found = FALSE;

            // start counting the results from the first result in the page
            $current = 1;
            foreach($nodes as $node)
            {

                $node = $node->nodeValue;
                // look for links that look like this: cmsreport.com › Blogs › Bryan's blog
                if(preg_match('/\s/',$node))
                {
                    $site = explode(' ',$node);
                }
                else
                {
                    $site = explode('/',$node);
                }

                $urls[$current] = $site[0];

                if($site[0] == $this->site)
                {
                    $found = TRUE;
                    $place = $current;
                }
                $current++;
            }

            if(isset($found) && $found !== FALSE)
            {
                return $place;
            }
            else
            {
                return FALSE;
            }
        }

    }


            $test =  new GoogleTracker(array('github'), 'en.wikipedia.org', 50);
            //$test->use_proxy('proxy.txt');
            $test->run();

            $results = $test->get_results();
            $debug = $test->get_debug_info();

            print_r($results);
            print_r($debug);

?>