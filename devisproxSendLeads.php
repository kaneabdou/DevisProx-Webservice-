<?php
/**
 * Created by PhpStorm.
 * User: abdou
 * Date: 16/05/2019
 * Time: 12:39
 */

namespace Landing\LandingBundle\Command\Maintenance;


use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use http\Exception;
use Landing\WebserviceBundle\Model\Producer\LandingProducer;
use PDO;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class ReadCsvCommand extends ContainerAwareCommand
{
    const USER = 'admin';
    const PASS = 'admin';
    const TABLE = 'send_lead';
    private $url = 'http://www.devisprox.com/daemon_incoming.php';
    private $lbError= false;
    /**
     * Database connexion
     * @var PDO
     */
    private $bdd;

    /**
     * Envoi le lead par webservice
     */
    protected function sendLead(array $laValues)
    {
        if(isset($laValues['diffsource']) && !empty($laValues['diffsource'])){
            $source = $laValues['diffsource'];
        }else{
            $source = '2913084';
        }

        $loRegion = $this->getContainer()->get('doctrine')->getManager()->getRepository('Landing\LandingBundle\Entity\City')
            ->findOneBy(array('country' => 'it','zipcode' => $laValues['zipcode']));

        $lsRegion = $loRegion->getDepartment() ? $loRegion->getDepartment() : '';
        parse_str($laValues['callWs'], $laScreen);
        //var_dump('avant', $laScreen);
        $lfMontant = $laScreen['montant_emprunt'];
        $lsType_secteur = $laScreen['type_secteur'];
        $laFields = array(
            'ref' => 1, //$laValues['subid'],
            'prenom' => $laValues['firstname'],
            'nom'   => $laValues['lastname'],
            'email' =>  $laValues['email'],
            'tel_mobile' => $laValues['phone'],
            'dob' => $laValues['birthday'],
            'cp'    => $laValues['zipcode'],
            'source' => $source,
            'pays' => 'IT',
            'idq'   => 604,
            'privacy' => 'Y',
            'status' => $laValues['status'],
            'url'    => $laValues['urlcreate'],
            'ip'     => $laValues['ipcreate'],
            'region' => $lsRegion,
            'montant_emprunt' => $lfMontant,
            'type_secteur'    => $lsType_secteur
        );
            //var_dump('apres', $laFields);exit();
        // ==== Appel du webservice ====
        $lsCall = $this->url . '?' . http_build_query($laFields);
        $loCurl = curl_init();
        curl_setopt($loCurl, CURLOPT_URL, $this->url);
        curl_setopt($loCurl, CURLOPT_POST, true);
        curl_setopt($loCurl, CURLOPT_POSTFIELDS, http_build_query($laFields));
        curl_setopt($loCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($loCurl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($loCurl, CURLOPT_HEADER, 0);
        $lsResponse = curl_exec($loCurl);
        $this->insertLead($laFields['status'],$lsCall, $lsResponse, $laFields['email']);
        $info = curl_getinfo($loCurl);
        curl_close($loCurl);
        // ==== Livraison ====
        $lbOk = preg_match('/OK/', $lsResponse);
        $lbDedub = preg_match("/1/i", $lsResponse);
        var_dump($lsCall);
        var_dump($lsResponse);

    }
// sendLead
    protected function configure()
    {
        $this->setName('maintenance:readCsv')
            ->setDescription('Read file CSV')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Send exports');
    } // configure

    /**
     * Execution
     *
     * @param InputInterface  $poInput  Arguments
     * @param OutputInterface $poOutput Screen output
     * @throws OptimisticLockException
     */
    protected function execute(InputInterface $poInput, OutputInterface $poOutput)
    {
        // ==== Initialization ====
        $this->manager = $this->getContainer()->get('doctrine')->getManager();
        $poInput->getOption('send');

        // ---- Send leads ----
        $poOutput->writeln("<comment>Calling send...</comment>");
        $this->connect();
        $this->readFile('/data/devisProx/NATEXO_4190_20190515-092120.csv');
    } // execute

    /**
     * Read file csv
     *
     * @param $file
     */
     protected function readFile($file) {

         // ==== Initialisations ====
        $file_to_read = fopen($file, "r");
        if (($handle = $file_to_read) !== FALSE) {
             $loop = 0;
	    while (($laData = fgetcsv($handle, 10000, ";")) !== FALSE) {
	        //var_dump($laData);
            $loop++;
            if($loop < 3) {
                continue;
            }
            $laTitle = array('email', 'firstname', 'lastname', 'phone', 'birthday', 'zipcode', 'status','timestamp','urlcreate','ipcreate', 'callWs','returnWs');

            $laReturn = array_combine($laTitle, $laData);
            $this->sendLead($laReturn);

    }
             }
             fclose($file_to_read);
         }

    /**
     * Connect to the database
     */
    private function connect()
    {
        try {
            $this->bdd = new PDO('mysql:host=192.168.43.19;port=3310;dbname=LANDING', ReadCsvCommand::USER, ReadCsvCommand::PASS);
            $this->bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->bdd->exec("SET CHARACTER SET utf8");
        } catch (Exception $e) {
            die('Erreur connexion à la base : ' . $e->getMessage());
        }

    } // connect
    /**
     * insert the lead into base
     */
    private function insertLead($psStatus, $psCall, $psResponse, $psEmail)
    {
        //var_dump($psCall);
        $stmt = $this->bdd->prepare("INSERT INTO PROD.".self::TABLE." (status, call_ws, return_ws, email ) VALUES (:status, :call_ws, :return_ws, :email )");
        $stmt->execute(array(
            "email" => $psEmail,
            "call_ws" => $psCall,
            "return_ws" => $psResponse,
            "status" => $psStatus
        ));
    }
}
