<?php


namespace Tmdk\Cub;

use Composer\Composer;
use Composer\Factory;
use Composer\Installer;
use Composer\IO\BaseIO;
use Composer\IO\IOInterface;
use Composer\Package\Locker;
use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;


/**
 * Class CubApplication
 * @package Tmdk\Cub
 */
class CubApplication
{

    /** @var string */
    protected $gitWorkingDir;

    /** @var  GitWrapper */
    protected $git;

    /** @var  GitWorkingCopy */
    protected $repository;

    /** @var  string */
    protected $repoUri;

    /** @var bool */
    protected $verbose = false;

    /** @var bool */
    protected $preferStable = true;

    /** @var  IOInterface */
    protected $io;

    /** @var  string */
    protected $composerWorkingDir;

    /** @var  string */
    protected $composerJsonFile;

    /** @var  string */
    protected $composerLockFile;

    /** @var  string */
    protected $branch;

    /**
     * CubApplication constructor.
     *
     * @param string $gitWorkingDir
     * @param $repoUri
     * @param $composerWorkingDir
     * @param bool $verbose
     * @param bool $preferStable
     * @param string $branch
     */
    function __construct(
        $gitWorkingDir,
        $repoUri,
        $composerWorkingDir,
        $verbose = false,
        $preferStable = true,
        $branch = 'develop'
    ) {
        $this->gitWorkingDir      = $gitWorkingDir;
        $this->repoUri            = $repoUri;
        $this->composerWorkingDir = $composerWorkingDir;
        $this->verbose            = $verbose;
        $this->preferStable       = $preferStable;
        $this->branch             = $branch;

        $this->composerJsonFile = "{$this->composerWorkingDir}/composer.json";
        $this->composerLockFile = "{$this->composerWorkingDir}/composer.lock";

        $this->git = new GitWrapper();
        $this->git->setEnvVar('HOME', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : $gitWorkingDir);

        $this->io = new IO($this->verbose);
    }

    static function run()
    {
        global $argv;

        $workingDir = getenv('CUB_WORKING_DIR') ?: getcwd();

        if ( ! is_dir($workingDir)) {
            if ( ! mkdir($workingDir)) {
                die("Could not create working directory $workingDir");
            }
        }

        $gitWorkingDir = realpath($workingDir);
        if ($gitWorkingDir === false) {
            die("Could not resolve real path of working directory $gitWorkingDir");
        }

        $composerJsonDir    = getenv('CUB_COMPOSER_JSON_DIR') ?: '';
        $composerWorkingDir = rtrim("$gitWorkingDir/$composerJsonDir", '/');

        $repoUri      = getenv('CUB_REPO') ?: null;
        $verbose      = getenv('CUB_VERBOSE') ?: false;
        $preferStable = getenv('CUB_PREFER_STABLE') ?: true;
        $branch       = getenv('CUB_BRANCH') ?: 'develop';

        if ($repoUri === null) {
            die('Environment variable CUB_REPO is undefined.');
        }

        if ( ! isset($argv[1])) {
            die('No package specified.');
        }

        $packageName = $argv[1];

        $app = new self($gitWorkingDir, $repoUri, $composerWorkingDir, $verbose, $preferStable, $branch);
        exit($app->update($packageName));
    }

    /**
     * @param $packageName
     *
     * @return int Result code (0 = successful, > 0 = error)
     */
    function update($packageName)
    {
        $this->initializeRepository();

        $this->resetRepoToOrigin();

        try {
            $update = $this->tryUpdatePackage($packageName);
        } catch (\Exception $exception) {
            $this->error("Failed to update package $packageName: {$exception->getMessage()}");

            return 1;
        }

        $commitCreated = false;
        $commitPushed  = false;

        if ($update->hasChanges()) {

            try {
                $commitCreated = $this->createCommit($update);
            } catch (\Exception $exception) {
                $this->error("Failed to create commit for update of $packageName: {$exception->getMessage()}");
            }

            if ($commitCreated) {
                try {
                    $commitPushed = $this->tryPushCommit();

                    $this->verbose("Pushed update of $packageName ({$update->getOldVersion()}) to {$update->getNewVersion()}");
                } catch (\Exception $exception) {
                    $this->error("Failed to push update of $packageName: {$exception->getMessage()}");

                }
            }

        } else {
            $this->verbose("Package $packageName not updated");
        }

        $this->verbose('Resetting and cleaning repository');
        $this->resetRepoToOrigin();

        return ( ! $update->hasChanges() || $commitPushed === true) ? 0 : 1;
    }

    /** @param string $message */
    function error($message)
    {
        $this->io->error($message);
    }

    /** @param string $message */
    function verbose($message)
    {
        $this->io->debug($message);
    }

    /**
     * @param $packageName
     *
     * @return PackageUpdate
     * @throws \Exception
     */
    function tryUpdatePackage($packageName)
    {
        $composer = $this->getComposer();
        $packages = [$packageName];
        $composer->getDownloadManager()->setOutputProgress($this->verbose);
        $install = Installer::create($this->io, $composer);

        $locker = $composer->getLocker();

        $packageBefore = $this->findPackageLockData($locker, $packageName);

        $unchanged = PackageUpdate::unchanged();

        $install
            ->setDryRun(false)
            ->setVerbose($this->verbose)
            ->setPreferSource(false)
            ->setPreferDist(true)
            ->setDevMode(false)
            ->setDumpAutoloader(false)
            ->setRunScripts(false)
            ->setSkipSuggest(true)
            ->setOptimizeAutoloader(false)
            ->setClassMapAuthoritative(false)
            ->setApcuAutoloader(false)
            ->setUpdate(true)
            ->setUpdateWhitelist($packages)
            ->setWhitelistDependencies(true)
            ->setIgnorePlatformRequirements(true)
            ->setPreferStable($this->preferStable)
            ->setPreferLowest(false)
            ->setExecuteOperations(false)
            ->setWriteLock(true);
        $install->disablePlugins();

        $ret = $install->run();

        if ($ret === 0) {
            $packageAfter = $this->findPackageLockData($locker, $packageName);

            $oldVersion = isset($packageBefore['version']) ? $packageBefore['version'] : null;
            $newVersion = isset($packageAfter['version']) ? $packageAfter['version'] : null;

            if ($oldVersion === null) {
                throw new \Exception('Could not determine current package version (lock data not found)');
            }
            if ($newVersion === null) {
                throw new \Exception('Could not determine new package version (lock data not found)');
            }

            return new PackageUpdate($packageBefore, $packageAfter);
        } else {
            return $unchanged;
        }
    }

    protected function resetRepoToOrigin()
    {
        $this->repository->fetch('origin', $this->branch);
        $this->repository->reset('--hard', "origin/{$this->branch}");
        $this->repository->clean('-d', '-f');
        $this->repository->checkout('.');
    }

    /**
     * @param Locker $locker
     * @param string $packageName
     *
     * @return array
     */
    protected function findPackageLockData($locker, $packageName)
    {
        $lockData = $locker->getLockData();

        if ( ! isset($lockData['packages'])) {
            throw new \InvalidArgumentException('Invalid LockData structure');
        }

        foreach ($lockData['packages'] as $package) {
            if ($package['name'] === $packageName) {
                return $package;
            }
        }

        return [];
    }

    /**
     * @param PackageUpdate $update
     *
     * @return bool
     */
    function createCommit(PackageUpdate $update)
    {
        $this->repository->add($this->composerLockFile);
        $this->repository->commit("Updating {$update->packageName} ({$update->getOldVersion()}) to {$update->getNewVersion()}");

        return true;
    }

    /**
     * @return boolean
     */
    function tryPushCommit()
    {
        $this->repository->push('-u', 'origin', $this->branch);

        return true;
    }

    function initializeRepository()
    {
        if (is_dir("{$this->gitWorkingDir}/.git")) {
            $this->repository = $this->git->workingCopy($this->gitWorkingDir);
        } else {
            $this->verbose("Cloning {$this->repoUri} into {$this->gitWorkingDir}");
            $this->repository = $this->git->cloneRepository($this->repoUri, $this->gitWorkingDir);
        }
    }

    /**  @return Composer */
    protected function getComposer()
    {
        $factory = new Factory();

        $composer = $factory->createComposer($this->io, $this->composerJsonFile, true, $this->composerWorkingDir);
        $composer->getInstallationManager()->addInstaller(new Installer\NoopInstaller());

        return $composer;
    }

}

class PackageUpdate
{
    /** @var  string */
    public $packageName;
    /** @var  string */
    public $oldVersion;
    /** @var  string */
    public $newVersion;
    /** @var  string */
    public $oldSourceRef;
    /** @var  string */
    public $newSourceRef;

    /**
     * PackageUpdate constructor.
     *
     * @param array $oldLockData
     * @param array $newLockData
     */
    public function __construct($oldLockData, $newLockData)
    {
        $this->packageName  = $oldLockData['name'];
        $this->oldVersion   = $oldLockData['version'];
        $this->oldSourceRef = $oldLockData['source']['reference'];
        $this->newVersion   = $newLockData['version'];
        $this->newSourceRef = $newLockData['source']['reference'];
    }

    function hasChanges()
    {
        return $this->oldSourceRef !== $this->newSourceRef;
    }

    protected function isVersioned($version)
    {
        return strncmp($version, 'dev-', 4) !== 0;
    }

    static function unchanged()
    {
        $empty = ['name' => '', 'version' => '', 'source' => ['reference' => '']];

        return new self($empty, $empty);
    }

    function getNewVersion()
    {
        return $this->isVersioned($this->newVersion) ? $this->newVersion : $this->shortRef($this->newSourceRef);
    }

    function getOldVersion()
    {
        return $this->isVersioned($this->oldVersion) ? $this->oldVersion : $this->shortRef($this->oldSourceRef);
    }

    protected function shortRef($ref)
    {
        return substr($ref, 0, 7);
    }
}

class IO extends BaseIO
{
    private $verbose = false;

    function __construct($verbose = false)
    {
        $this->verbose = $verbose;
    }

    /**
     * Is this input means interactive?
     *
     * @return bool
     */
    public function isInteractive()
    {
        return false;
    }

    /**
     * Is this output verbose?
     *
     * @return bool
     */
    public function isVerbose()
    {
        return $this->verbose;
    }

    /**
     * Is the output very verbose?
     *
     * @return bool
     */
    public function isVeryVerbose()
    {
        return $this->verbose;
    }

    /**
     * Is the output in debug verbosity?
     *
     * @return bool
     */
    public function isDebug()
    {
        return false;
    }

    /**
     * Is this output decorated?
     *
     * @return bool
     */
    public function isDecorated()
    {
        return false;
    }

    /**
     * Writes a message to the output.
     *
     * @param string|array $messages The message as an array of lines or a single string
     * @param bool $newline Whether to add a newline or not
     * @param int $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL)
    {
        if (is_string($messages)) {
            $messages = [$messages];
        }

        foreach ($messages as $message) {
            echo "[INFO] " . $message . ($newline ? "\n" : '');
        }
    }

    /**
     * Writes a message to the error output.
     *
     * @param string|array $messages The message as an array of lines or a single string
     * @param bool $newline Whether to add a newline or not
     * @param int $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
        if (is_string($messages)) {
            $messages = [$messages];
        }

        foreach ($messages as $message) {
            if ($verbosity === self::NORMAL || $verbosity === self::QUIET || $this->verbose) {
                echo "[EXTRA] " . $message . ($newline ? "\n" : '');
            }
        }
    }

    /**
     * Overwrites a previous message to the output.
     *
     * @param string|array $messages The message as an array of lines or a single string
     * @param bool $newline Whether to add a newline or not
     * @param int $size The size of line
     * @param int $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function overwrite($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        $this->write($messages, $newline, $verbosity);
    }

    /**
     * Overwrites a previous message to the error output.
     *
     * @param string|array $messages The message as an array of lines or a single string
     * @param bool $newline Whether to add a newline or not
     * @param int $size The size of line
     * @param int $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function overwriteError($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        $this->writeError($messages, $newline, $verbosity);
    }

    /**
     * Asks a question to the user.
     *
     * @param string|array $question The question to ask
     * @param string $default The default answer if none is given by the user
     *
     * @throws \RuntimeException If there is no data to read in the input stream
     * @return string            The user answer
     */
    public function ask($question, $default = null)
    {
        throw new \RuntimeException('Not interactive');
    }

    /**
     * Asks a confirmation to the user.
     *
     * The question will be asked until the user answers by nothing, yes, or no.
     *
     * @param string|array $question The question to ask
     * @param bool $default The default answer if the user enters nothing
     *
     * @return bool true if the user has confirmed, false otherwise
     */
    public function askConfirmation($question, $default = true)
    {
        return false;
    }

    /**
     * Asks for a value and validates the response.
     *
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param string|array $question The question to ask
     * @param callback $validator A PHP callback
     * @param null|int $attempts Max number of times to ask before giving up (default of null means infinite)
     * @param mixed $default The default answer if none is given by the user
     *
     * @throws \Exception When any of the validators return an error
     * @return mixed
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        throw new \Exception('Not interactive');
    }

    /**
     * Asks a question to the user and hide the answer.
     *
     * @param string $question The question to ask
     *
     * @return string The answer
     */
    public function askAndHideAnswer($question)
    {
        return '';
    }

    /**
     * Asks the user to select a value.
     *
     * @param string|array $question The question to ask
     * @param array $choices List of choices to pick from
     * @param bool|string $default The default answer if the user enters nothing
     * @param bool|int $attempts Max number of times to ask before giving up (false by default, which means infinite)
     * @param string $errorMessage Message which will be shown if invalid value from choice list would be picked
     * @param bool $multiselect Select more than one value separated by comma
     *
     * @throws \InvalidArgumentException
     * @return int|string|array          The selected value or values (the key of the choices array)
     */
    public function select(
        $question,
        $choices,
        $default,
        $attempts = false,
        $errorMessage = 'Value "%s" is invalid',
        $multiselect = false
    ) {
        throw new \InvalidArgumentException('Not interactive');
    }

}