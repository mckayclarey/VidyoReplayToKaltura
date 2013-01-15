<?php
ini_set('memory_limit', '128M');
set_time_limit(0);
//initialize the Kaltura client -
require_once('kaltura-php5/KalturaClient.php');
require_once('Vidyo2KalturaConfig.php');
class syncv2k 
{
	private $client;
	private $vidyoClient;
	private $currdir;
	private $kalturaentries;
	const logFilename = 'syncVidyo2Kaltura.log';
	public $initializeSuccess = false;
	
	function __construct(KalturaClient $client = null)
	{
		$this->currdir = dirname( realpath( __FILE__ ) ) . DIRECTORY_SEPARATOR;
		$this->cleanLogFile();
		$this->initializeClients();
	}
	
	function cleanLogFile() 
	{
		$logFile = $this->currdir.syncv2k::logFilename;
		$fd = fopen($logFile, "w");
		fwrite($fd, '');
		fclose($fd);
	}
	
	function logToFile($msg)
	{
		$logFile = $this->currdir.syncv2k::logFilename;
		$fd = fopen($logFile, "a");
		$str = "[" . date("Y/m/d h:i:s", mktime()) . "] " . $msg;
		fwrite($fd, $str . "\n");
		fclose($fd);
	}
	
	/**
	 * initialize the Kaltura and Vidyo clients
	 * @access public
	 */
	public function initializeClients() 
	{
		$kConfig = new KalturaConfiguration(Vidyo2KalturaConfig::PARTNER_ID);
		$this->client = new KalturaClient($kConfig);
		$ks = $this->client->generateSession(Vidyo2KalturaConfig::ADMIN_SECRET, 'vidyoSyncClient', KalturaSessionType::ADMIN, Vidyo2KalturaConfig::PARTNER_ID);
		$this->client->setKs($ks);
		$this->logToFile('SUCCESS initializing Kaltura success');
		
		//initialize the Vidyo client - 
		require_once 'vidyomanage/VidyoReplayContentManagementService.php';
		$this->vidyoClient = new VidyoReplayContentManagementService(array(
				'login' => Vidyo2KalturaConfig::VIDYO_USER,
				'password' => Vidyo2KalturaConfig::VIDYO_PASSWORD,
				'trace' => 1,
				'exceptions' => 1,
				'soap_version' => SOAP_1_2),
				Vidyo2KalturaConfig::VIDYO_REPLAY_SERVER.'/replay/services/VidyoReplayContentManagementService?wsdl')
				or exit("Unable to create soap client!");

		$this->logToFile('SUCCESS initializing Vidyo success');
		//} catch (Exception $ex) {
		//	$this->logToFile('ERROR: VidyoReplay connection fault - faultcode: '.print_r($ex, true));
		//	$this->initializeSuccess = false;
		//	return;

		$this->initializeSuccess = true;
	}
	
	/**
	 * lists both Kaltura and Vidyo, checks if their synchronized, if not, copies new recordings from Vidyo to Kaltura
	 * @access public
	 */
	public function syncVidyo2Kaltura ()
	{
		// list Kaltura entries that are Vidyo recording, descending by date
		$listFilter = new KalturaMediaEntryFilter();
		$listFilter->orderBy = KalturaMediaEntryOrderBy::CREATED_AT_DESC; //in Flacon we can do: PARTNER_SORT_VALUE_DESC;
		$listFilter->statusIn = '-2,-1,0,1,2,4,5,6,7'; //add 3 to include DELETED entries
		$listFilter->tagsLike = Vidyo2KalturaConfig::VIDYO_KALTURA_TAGS;
		$pager = new KalturaFilterPager();
		$pager->pageSize = 1;
		$pager->pageIndex = 1;
		$results = $this->client->media->listAction($listFilter, $pager);
		$entry = null;
		$recordingStartSearch = 0;
		$hasRecordingsInKaltura = count($results->objects);
		$this->logToFile('*=== '.($hasRecordingsInKaltura > 0 ? 'has recordings in Kaltura' : 'no recordings in Kaltura found'));
		if ($hasRecordingsInKaltura) {
			$entry = $results->objects[0];
			$this->logToFile('==== last vidyoRecording on Kaltura is: '.$entry->partnerSortValue . ', GUID: ' . $entry->referenceId);
			$this->kalturaentries[$entry->referenceId] = $entry;
			$recordingStartSearch = $entry->partnerSortValue;
		}
		
		// list Vidyo recordings, ascending by date
		$recordsSearch = new RecordsSearchRequest(null, null, null, null, null, sortBy::date, sortDirection::ASC, null, $recordingStartSearch, null);
		$recordsSearchResult = $this->vidyoClient->RecordsSearch($recordsSearch);
		$this->logToFile('SUCCESS list Vidyo success');
		$vidyoRecordsCount = count($recordsSearchResult->records);
		$this->logToFile('==== syncing '.$vidyoRecordsCount.' new recordings');
		$lastVidyoRecording = null;	
		if ($vidyoRecordsCount > 1) {
			if ($lastVidyoRecording < 1) $lastVidyoRecording = 1;
			$lastVidyoRecording = $recordsSearchResult->records[($lastVidyoRecording-1)];
		} else {
			$lastVidyoRecording = $recordsSearchResult->records;
		}
		if (($vidyoRecordsCount == 0) || ($entry && $lastVidyoRecording->id == $entry->partnerSortValue)) {
			$this->logToFile('KALTURA AND VIDYO ARE ALREADY IN SYNC...');
			$this->logToFile('EXITING.');
		} else {
			if ($vidyoRecordsCount > 1) {
				$vidyoRecords = $recordsSearchResult->records;
				foreach ($vidyoRecords as $recording) 
				{
					$this->logToFile('==== syncing '.$recording->id . ', GUID: ' . $recording->guid);
					$this->copyVidyoRecording2Kaltura($recording);
					$this->logToFile('==== SUCCESS syncing '.$recording->guid.' ====');
				}
			} else {
				$this->logToFile('==== syncing '.$lastVidyoRecording->id . ', GUID: ' . $lastVidyoRecording->guid);
				$this->copyVidyoRecording2Kaltura($lastVidyoRecording);
				$this->logToFile('==== SUCCESS syncing '.$lastVidyoRecording->guid.' ====');
			}
		}

		$this->logToFile('**** SUCCESS Kaltura and Vidyo are synced! ('.$recordsSearchResult->searchCount.' recordings)');
	}
	
	/**
	 * helper function to copy a recording video and its metadata from Vidyo to Kaltura 
	 * @access private
	 */
	private function copyVidyoRecording2Kaltura ($recording) 
	{
		// here we deconstruct the URL VidyoReplay is giving us, and we add the http User and Passwrod to it
                // that way, we allow Kaltura to import the file directly from the VidyoReplay server
                $url = $recording->fileLink;
                $urlParts = parse_url($url);
                $recordingVideoFileUrl = $urlParts["scheme"].'://'.Vidyo2KalturaConfig::VIDYO_USER.':'.Vidyo2KalturaConfig::VIDYO_PASSWORD.'@'.$urlParts["host"].$urlParts["path"].'?'.$urlParts["query"];
                // create a new Kaltura Media Entry and copy the Vidyo Recording metadata to it
                $entry = new KalturaMediaEntry();
                $entry->mediaType = KalturaMediaType::VIDEO;
                $entry->referenceId = $recording->guid;
                $entry->partnerSortValue = $recording->id;
                $entry->userId = $recording->userFullName;
                $entry->tags = Vidyo2KalturaConfig::VIDYO_KALTURA_TAGS.','.$recording->tags;
                $entry->name = $recording->title;
                $entry->categories = Vidyo2KalturaConfig::KALTURA_VIDYO_RECORDINGS_CATEGORY;
                $entry = $this->client->media->add($entry);
                $this->logToFile('==== SUCCESS creating new Kaltura Entry Id: '.$entry->id.' of recording: '.$recording->id);

                // make Kaltura import the recording file from the VidyoReplay server 
                $resource = new KalturaUrlResource();
                $resource->url = $recordingVideoFileUrl;
                $this->client->media->addContent($entry->id, $resource);
                $this->logToFile('==== SUCCESS importing Vidyo recording: '.$recordingVideoFileUrl.' to Kaltura Entry: '.$entry->id);
	}
}

$syncer = new syncv2k();
if ($syncer->initializeSuccess == true) {
	$syncer->syncVidyo2Kaltura();
}
