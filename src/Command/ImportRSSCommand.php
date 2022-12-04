<?php

declare(strict_types=1);

/*
 * This source file is subject to the GPL v2, bundled
 * with this source code in the file LICENSE.
 */

namespace App\Command;

use SimpleXMLElement;
use App\Entity\Podcast;
use App\Entity\Season;
use App\Entity\Episode;
use App\Util\TmpFile;
use App\Repository\PodcastRepository;
use App\Repository\SeasonRepository;
use App\Repository\EpisodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Nines\MediaBundle\Entity\Image;
use Nines\MediaBundle\Service\ImageManager;

class ImportRSSCommand extends Command
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    private ?SeasonRepository $seasonRepository = null;
    private ?PodcastRepository $podcastRepository = null;
    private ?ImageManager $imageManager = null;
    protected Crawler $crawler;
    protected $client;
    protected static $defaultName = 'app:import:rss';

    protected const NS = [
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'atom' => 'http://www.w3.org/2005/Atom',
        'googleplay' => 'http://www.google.com/schemas/play-podcasts/1.0',
        'spotify' => 'http://www.spotify.com/ns/rss',
        'podcast' => 'https://podcastindex.org/namespace/1.0',
        'media' => 'http://search.yahoo.com/mrss/'
    ];

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->crawler = new Crawler();
        $this->client = HttpClient::create();
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this->setDescription('Import data');
        $this->addArgument('podcastId', InputArgument::REQUIRED, 'ID of podcast.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $serializer = new Serializer($normalizers, $encoders);
        $this->podcast = $this->podcastRepository->find($input->getArgument('podcastId'));
        if (!$this->podcast) {
            $output->writeln('Cannot find podcast ' . $input->getArgument('podcastId'));
            return 1;
        }
        $rss = $this->podcast->getRss();
        if ( ! $rss) {
            $output->writeln('Podcast does not have RSS feed');
            return 1;
        };

        if (count($this->podcast->getEpisodes()) > 0 ){
            $output->writeln('Podcast has episodes already');
            return 1;
        }

        $xml = simplexml_load_file($rss, 'SimpleXMLElement', LIBXML_NOCDATA);
        $domnode = dom_import_simplexml($xml);
        $document = new \DomDocument();
        $domnode = $document->importNode($domnode, true);
        $document->appendChild($domnode);
        $document->load($rss);
        $this->xpath = new \DOMXPath($document);
        // First basic podcast info
        foreach($this->xpath->query('//channel/*') as $child) {
            $name = $child->localName;
            switch ($name) {
                case 'image':
                    if (count($this->podcast->getImages()) === 0) {
                        echo 'Uploading new image';
                        $img = $this->xpath->query('child::url', $child);
                        if ($img->length === 1) {
                            $this->addImageToEntity($img[0]->nodeValue, $this->podcast);
                        }
                    }
            }
        }

        // Now let's do episodes;
        foreach($this->xpath->query('//channel/item') as $item) {
            $this->processEpisode($item);
        }



        $this->em->flush();
        $this->em->clear();
        return 0;



        /*

        foreach($xpath->query('//image/url') as $el){
            $output->writeln($el->nodeValue);

            // gets the response body as a string
            $podcast->setSubtitle('Did this set?');
            $output->writeln(var_dump($podcast));
            $this->em->persist($podcast);
        }; */



    }

    /**
     * @param $item \DOMElement
     * @return void
     */
    public function processEpisode(\DOMElement $item){
        $episode = new Episode();
        $persist = true;
        $props = [];
        $i = 0;
        foreach($this->xpath->query('child::*', $item) as $node){
            $name = $node->nodeName;
            $local = $node->localName;
            switch ($name){
                case 'itunes:title':
                case 'title':
                    if (!in_array($local, $props)){
                        $props[] = $local;
                        $episode->setTitle($node->nodeValue);
                    }
                    break;

                case 'description':
                    if (! in_array($local, $props)){
                        $props[] = $local;
                        $episode->setDescription($node->nodeValue);
                    }
                    break;
               // TODO: Add a season, if we need to (perhaps this would be better
                // handled in a first pass?
                case 'season':
                case 'itunes:season':
                    echo "Found season element\n";
                    $seasonNum = intval($node->nodeValue);
                    $thisSeason = $this->podcast->getSeasons()->filter(
                       function(Season $season) use ($seasonNum) {
                                 return ($season->getNumber() === $seasonNum);
                             });
                        if (count($thisSeason) > 0){
                        } else {
                            echo 'DIDNT FIND THIS SEASON';
                           // var_dump($thisSeason);
                        }
                    break;
            }
            $episode->setPodcast($this->podcast);

            //echo var_dump($episode);
        }
    }

    public function addImageToEntity($imgUrl, $entity, $str = null){
        $description = 'Image for ' . $this->getEntityDescription($entity, $str);
        echo 'Writing ' . $description;
        $upload = $this->upload($imgUrl);
        $img = new Image();
        $img->setFile($upload);
        $img->setPublic(true);
        $img->setEntity($entity);
        $img->setDescription($description);
        $img->prePersist();
        $this->em->persist($img);
        $entity->addImage($img);
    }

    /**
     * @param $url
     * @return An uploaded file
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function upload($url, $filename = null){
        $response = $this->client->request('GET', $url);
        $tmpFile = new TmpFile($response->getContent());
        $upload = new UploadedFile($tmpFile->getRealPath(), basename(pathinfo($url, PHP_URL_PATH)), $tmpFile->getMimeType(),null,null,true);
        return $upload;
    }

    public function getEntityDescription($entity, $str = null){
        if ($str != null){
            return $str;
        }
        //TODO: PHP8 should be able to check implements
        if (method_exists($entity,'__toString')){
            return (string) $entity;
        }
        return get_class($entity) . ' ' . $entity->getId();
    }



    
    /**
     * @required
     */
    public function setPodcastRepository(PodcastRepository $podcastRepository) : void {
        $this->podcastRepository = $podcastRepository;
    }

    public function setImageManager(ImageManager $imageManager) : void {
        $this->imageManager = $imageManager;
    }




}
