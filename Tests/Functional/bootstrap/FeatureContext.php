<?php

use Behat\Behat\Context\BehatContext;

use Symfony\Component\HttpFoundation\File\UploadedFile;

use ETNA\FeatureContext as EtnaFeatureContext;
use ETNA\Utils\CsvUtils;
use ETNA\Utils\FileUtils as EtnaFileUtils;
use ETNA\Utils\LoginUtils;
use ETNA\Utils\NotifyUtils;
use ETNA\Utils\PasswordUtils;

/**
 * Features context
 */
class FeatureContext extends BehatContext
{
    use EtnaFeatureContext\Coverage;
    use EtnaFeatureContext\Check;
    use EtnaFeatureContext\setUpScenarioDirectories;
    use EtnaFeatureContext\SilexApplication;
    use EtnaFeatureContext\RabbitMQ;

    static private $_parameters;
    static private $vhosts;

    private $result    = null;
    private $csv_lines = null;
    private $error     = null;

    /**
     * Initialize context
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        self::$_parameters = $parameters;
        self::$vhosts      = ["/test-behat"];

        ini_set('display_errors', true);
        ini_set('xdebug.var_display_max_depth', 100);
        ini_set('xdebug.var_display_max_children', 100);
        ini_set('xdebug.var_display_max_data', 100);
        error_reporting(E_ALL);
    }

    /**
     * @When /^je convertis en csv le tableau contenu dans "([^"]*)"(?: en prefixant avec "([^"]*)"?)?$/
     */
    public function jeConvertisEnCsvLeTableauContenuDans($filename, $prefix = null)
    {
        $filepath = realpath($this->requests_path . "/" . $filename);

        $array_to_convert = file_get_contents($filepath);
        $array_to_convert = json_decode($array_to_convert, true);

        if (null === $array_to_convert) {
            throw new Exception("json_decode error");
        }

        $array_to_convert = array_map(
            function($line) use ($prefix) {
                return CsvUtils::getTokenFromArray($line, $prefix);
            },
            $array_to_convert
        );

        try {
            $this->result = CsvUtils::arrayToCsv($array_to_convert, $this->csv_lines);
        } catch (\Exception $exception) {
            $this->error = $exception;
        }

    }

    /**
     * @When /^j'envoie un mail a "([^"]*)" avec "([^"]*)" avec le titre "([^"]*)" et le template contenu dans le fichier "([^"]*)" et les tokens contenus dans "([^"]*)"(?: avec comme pièce jointe les fichiers "([^"]*)"?)?$/
     */
    public function jEnvoieUnMail($to_email, $from_email, $title, $template_filename, $tokens_filename, $files = null)
    {
        $template_filepath = realpath($this->requests_path . "/" . $template_filename);
        $template          = file_get_contents($template_filepath);
        $tokens_filepath   = realpath($this->requests_path . "/" . $tokens_filename);
        $tokens_content    = file_get_contents($tokens_filepath);
        $tokens            = json_decode($tokens_content, true);

        $mail_opt = [];
        if (null !== $files) {
            $mail_opt = [
                "files" => NotifyUtils::prepareFilesForMail(
                    array_map(
                        function ($filename) {
                            $filename = trim($filename);
                            return [
                                "name" => $filename,
                                "path" => "{$this->requests_path}/{$filename}"
                            ];
                        },
                        explode(";", $files)
                    )
                )
            ];
        }

        try {
            NotifyUtils::sendMail(self::$silex_app, $title, $template, $from_email, $to_email, $tokens, $mail_opt);
        } catch (\Exception $exception) {
            $this->error = $exception;
        }
    }

    /**
     * @When /^je lance une impression avec le template "([^"]*)" et les données contenues dans "([^"]*)"(?: dans la queue "([^"]*)"?)?$/
     */
    public function jeLanceUneImpression($template_filename, $tokens_filename, $queue_name = null)
    {
        $template_filepath = realpath($this->requests_path . "/" . $template_filename);
        $template          = file_get_contents($template_filepath);
        $tokens_filepath   = realpath($this->requests_path . "/" . $tokens_filename);
        $tokens_content    = file_get_contents($tokens_filepath);
        $tokens            = json_decode($tokens_content, true);

        try {
            NotifyUtils::sendPrint(self::$silex_app, $template_filename, $template, $queue_name, $tokens);
        } catch (\Exception $exception) {
            $this->error = $exception;
        }
    }

    /**
     * @Then /^il doit y avoir un message dans la file "([^"]*)" avec le corps contenu dans "([^"]*)"$/
     */
    public function ilDoitYavoirUnMessageDansLaFileAvecLeCorpsContenuDans($queue = null, $body = null)
    {
        $result_path = $this->results_path . $body;
        if (null !== $body) {
            if (!file_exists($result_path)) {
                throw new Exception("File not found : {$this->results_path}${body}");
            }
        }

        $body          = file_get_contents($result_path);
        $parsed_wanted = json_decode($body);

        $channel = self::$silex_app["amqp.queues"][$queue]->getChannel();

        $response_msg    = $channel->basic_get($queue);
        $parsed_response = json_decode($response_msg->body);
        $this->check($parsed_wanted, $parsed_response, "result", $errors);
        if ($nb_errors = count($errors)) {
            echo json_encode($parsed_response, JSON_PRETTY_PRINT);
            throw new Exception("{$nb_errors} errors :\n" . implode("\n", $errors));
        }
    }

    /**
     * @Then /^le résultat devrait être identique à "(.*)"$/
     * @Then /^le résultat devrait être identique au JSON suivant :$/
     * @Then /^le résultat devrait ressembler au JSON suivant :$/
     * @param string $string
     */
    public function leResultatDevraitRessemblerAuJsonSuivant($string)
    {
        $expected_result = json_decode($string);
        $real_result     = json_decode(json_encode($this->result));
        if (null === $expected_result) {
            throw new Exception("json_decode error");
        }

        $this->check($expected_result, $real_result, "result", $errors);
        if (0 < ($nb_errors = count($errors))) {
            echo json_encode($real_result, JSON_PRETTY_PRINT);
            throw new Exception("{$nb_errors} errors :\n" . implode("\n", $errors));
        }
    }

    /**
     * @Then /^le résultat devrait être identique au fichier "(.*)"$/
     */
    public function leResultatDevraitRessemblerAuFichier($file)
    {
        $file = realpath($this->results_path . "/" . $file);
        $this->leResultatDevraitRessemblerAuJsonSuivant(file_get_contents($file));
    }

    /**
     * @Then /^le résultat devrait être identique au fichier csv "(.*)"$/
     */
    public function leResultatDevraitRessemblerAuFichierCsv($filename)
    {
        $filepath         = realpath($this->results_path . "/" . $filename);
        $expected_content = trim(file_get_contents($filepath), "\n");

        if ($expected_content !== $this->result) {
            echo "\n", $expected_content, "\n\n";
            echo $this->result, "\n\n";
            throw new Exception("CSVs results are not the same");
        }
    }

    /**
     * @Then /^le csv sortant devrait contenir (\d+) lignes$/
     */
    public function leCsvSortantDevraitContenirTantDeLignes($expected_nb_lines)
    {
        if ($this->csv_lines !== intval($expected_nb_lines)) {
            throw new Exception("Expected {$expected_nb_lines} CSV lines but got {$this->csv_lines}");
        }
    }

    /**
     * @When /^je génère un mot de passe(?: avec (\d+) lettres(?: et (\d+) autres caractères)?)?$/
     */
    public function jeGenereUnMotDePasse($letters = 6, $non_letters = 2)
    {
        $this->result = PasswordUtils::generate($letters, $non_letters);
    }

    /**
     * @Then /^le mot de passe devrait contenir (\d+) lettres et (\d+) autres caractères$/
     */
    public function leMotDePasseDevraitContenir($letters, $non_letters)
    {
        $regex  = "/(?=^([^A-Za-z]*[a-zA-Z]){" . $letters;
        $regex .= "}[^A-Za-z]*$)(?=^([^0-9\.]*[0-9\.]){" . $non_letters . "}[^0-9\.]*$)/";

        if (1 !== preg_match($regex, $this->result)) {
            throw new Exception("Generated password does not match");
        }
    }

    /**
     * @When /^je chiffre le mot de passe "([^"]*)"$/
     */
    public function jeChiffreLeMotDePasse($password)
    {
        $this->result = PasswordUtils::encrypt($password);
    }

    /**
     * @When /^je veux générer un login avec le nom "([^"]*)" et le prénom "([^"]*)"$/
     */
    public function jeVeuxGenererUnLogin($lastname, $firstname)
    {
        try {
            $this->result = LoginUtils::generate($lastname, $firstname);
        } catch (\Exception $exception) {
            $this->error = $exception;
        }
    }

    /**
     * @When /^je veux récupérer le contenu du fichier "([^"]*)"$/
     */
    public function jeVeuxRecupererLeContenuDuFichier($filename)
    {
        $filepath = realpath($this->requests_path . "/" . $filename);
        $file     = new UploadedFile($filepath, $filename);

        try {
            $this->result = EtnaFileUtils::handleFile($file);
        } catch (\Exception $exception) {
            $this->error = $exception;
        }
    }

    /**
     * @Then /^il devrait y avoir eu une erreur$/
     */
    public function ilDevraitYavoirEuUneErreur()
    {
        if (null === $this->error) {
            throw new \Exception("Expecting an error to happen but everything went good");
        }
    }

    /**
     * @Then /^il ne devrait pas y avoir eu une erreur$/
     */
    public function ilNeDevraitPasYavoirEuUneErreur()
    {
        if (null !== $this->error) {
            throw new \Exception("Wasn't expecting an error to happen but went bad : {$this->error->getMessage()}");
        }
    }

    /**
     * @Then /^le message d'erreur devrait être "([^"]*)"(?: et le code (\d+)?)$/
     */
    public function leMessageDerreurDevraitEtre($msg, $code = null)
    {
        $code = null === $code ?: intval($code);
        if (null === $this->error || $this->error->getMessage() !== $msg) {
            throw new Exception("Expecting error message to be \"{$msg}\" but got \"{$this->error->getMessage()}\"");
        }
        if (null !== $code && $this->error->getCode() !== $code) {
            throw new Exception("Expecting error code to be \"{$code}\" but got \"{$this->error->getCode()}\"");
        }
    }
}
