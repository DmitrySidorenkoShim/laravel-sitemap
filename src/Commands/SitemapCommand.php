<?php

namespace BringYourOwnIdeas\LaravelSitemap\Commands;

use Exception;
use DOMDocument;
use Carbon\Carbon;
use SimpleXMLElement;
use VDB\Spider\Spider;
use Spatie\Robots\Robots;
use Illuminate\Console\Command;
use VDB\Spider\Event\SpiderEvents;
use VDB\Spider\QueueManager\InMemoryQueueManager;
use VDB\Spider\QueueManager\QueueManagerInterface;
use VDB\Spider\Filter\Prefetch\AllowedHostsFilter;
use VDB\Spider\Discoverer\XPathExpressionDiscoverer;
use BringYourOwnIdeas\LaravelSitemap\Handlers\StatsHandler;

/**
 * Class SitemapCommand
 *
 * @package BringYourOwnIdeas\LaravelSitemap\Commands
 */
class SitemapCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sitemap:generate';

    /**
     * @var string
     */
    protected $description = 'Crawl the site and generate the sitemap.xml file';

    /**
     * Generate the sitemap
     *
     * @return int
     */
    public function handle(): int
    {
        // Crawl the site
        $this->info('Starting site crawl...');
        $resources = $this->crawlWebsite(env('APP_URL'));

        // Write the sitemap
        $this->info('Writing sitemap.xml into public directory...');
        $this->writeSitemap($resources);

        // Signal completion
        $this->info('Sitemap generation completed.');

        return Command::SUCCESS;
    }

    /**
     * Crawler over the website.
     *
     * @param string $url
     * @return array $resources
     */
    protected function crawlWebsite(string $url): array
    {
        // Load the robots.txt from the site.
        $robots_url = $url . '/robots.txt';
        $this->info('Loading robots.txt from ' . $robots_url);
        $robots = Robots::create()->withTxt($robots_url);

        // Create Spider
        $spider = new Spider($url);

        // Add a URI discoverer. Without it, the spider does nothing.
        // In this case, we want <a> tags and the canonical link
        $spider->getDiscovererSet()->set(new XPathExpressionDiscoverer("//a|//link[@rel=\"canonical\"]//a"));
        $spider->getDiscovererSet()->addFilter(new AllowedHostsFilter([$url], true));

        // Set limits
        $spider->getDiscovererSet()->maxDepth = 25;
        $spider->getQueueManager()->maxQueueSize = 1000;

        // Let's add something to enable us to stop the script
        $spider->getDispatcher()->addListener(
            SpiderEvents::SPIDER_CRAWL_USER_STOPPED,
            function () {
                echo "Crawl aborted.";
                exit();
            }
        );

        // Add a listener to collect stats to the Spider and the QueueMananger.
        // There are more components that dispatch events you can use.
        $statsHandler = new StatsHandler();
        /** @var QueueManagerInterface|InMemoryQueueManager $queueManager */
        $queueManager = $spider->getQueueManager();
        $queueManager->getDispatcher()->addSubscriber($statsHandler);
        $spider->getDispatcher()->addSubscriber($statsHandler);

        // Execute crawl
        $spider->crawl();

        // Build a report
        $this->comment("Enqueued:  " . count($statsHandler->getQueued()));
        $this->comment("Skipped:   " . count($statsHandler->getFiltered()));
        $this->comment("Failed:    " . count($statsHandler->getFailed()));
        $this->comment("Persisted: " . count($statsHandler->getPersisted()));

        // Finally, we could do some processing on the downloaded resources
        // In this example we will echo the title of all resources
        $this->comment("\nResources:");
        $resources = [];
        foreach ($spider->getDownloader()->getPersistenceHandler() as $resource) {
            // Get URL
            $url = $resource->getUri()->toString();

            // Does this page have a noindex?
            // <meta name="robots" content="noindex, nofollow" />
            $noindex = false;
            if ($resource->getCrawler()->filterXpath('//meta[@name="robots"]')->count() > 0) {
                $noindex = (str_contains(
                    $resource->getCrawler()->filterXpath('//meta[@name="robots"]')->attr('content'),
                    'noindex'
                ));

                $this->info(sprintf(" - Skipping %s (on-page no-index)", $url));
            }

            // Set noindex, if disallowed by robots.txt.
            if (!$robots->mayIndex($url)) {
                $noindex = true;

                $this->info(sprintf(" - Skipping %s (robots.txt no-index)", $url));
            }

            // Check if we got a time to?
            $time = '';
            if ($resource->getCrawler()->filterXpath('//meta[@property="article:modified_time"]')->count() > 0) {
                $time = $resource->getCrawler()->filterXpath('//meta[@property="article:modified_time"]')->attr('content');
            }

            // Is there a canonical for this page?
            $canonical = '';
            if ($resource->getCrawler()->filterXpath('//link[@rel="canonical"]')->count() > 0) {
                $canonical = $resource->getCrawler()->filterXpath('//link[@rel="canonical"]')->attr('href');

                if ($canonical !== $url) {
                    $this->info(sprintf(" - Canonicalizing %s to %s", $url, $canonical));
                }
            }

            // Only add in if it should be indexed and isn't in the list already...
            $url = ($canonical == '') ? $url : $canonical;
            if (!$noindex && !array_key_exists($url, $resources)) {
                $resources[$url] = ($time == '') ? date('Y-m-d\Th:i:s') : $time;

                $this->comment(sprintf(" - Adding %s", $url));
            }
        }

        // Return the resources for processing of the sitemap.
        return $resources;
    }

    /**
     * Write the sitemap as a file.
     *
     * @param  array  $resources
     *
     * @return void
     **/
    protected function writeSitemap(array $resources): void
    {
        // Prepare XML
        $urlset = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="https://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="https://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="https://www.sitemaps.org/schemas/sitemap/0.9 https://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"></urlset>');

        // Add all resources in
        foreach ($resources as $url => $lastmod) {
            // Ensure the lastmod has a timezone by parsing and writing it out again
            $lastmod = Carbon::parse($lastmod);
            if ($lastmod->tzName === 'UTC' && date_default_timezone_get() != null) {
                $lastmod->shiftTimezone(date_default_timezone_get());
            }

            // Add the node
            $entry = $urlset->addChild('url');
            $entry->addChild('loc', $url);
            $entry->addChild('lastmod', $lastmod->format('Y-m-d\TH:i:sP'));
            $entry->addChild('priority', str_replace(',', '.', round((1 - .05 * Substr_count($url, '/')), 1)));
            $entry->addChild('changefreq', 'monthly');
        }

        // Beautify XML (actually not needed, but neat)
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($urlset->asXML());
        $dom->formatOutput = true;

        // Write a file
        try {
            file_put_contents(public_path() . '/sitemap.xml', $dom->saveXML());
        } catch (Exception $exception) {
            $this->error("Failed to write sitemap.xml: {$exception->getMessage()}.");
        }
    }
}
