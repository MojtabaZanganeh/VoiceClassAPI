<?php
namespace Classes\Base;

use Classes\Base\Database;
use Classes\Support\Emails;
use Exception;

abstract class Cron
{
    use Base;
    protected Database $db;
    protected Emails $notifier;
    protected string $cronName;
    protected string $logFile;
    protected string $logMessage = 'Cron completed';
    protected int $startTime;
    protected string $currentTime;
    protected array $cronTimes;

    protected bool $failed = false;
    protected string $errorMessage = '';
    protected string $errorTrace = '';

    public function __construct(string $logFile, ?string $cronName = null)
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->safeLoad();

        $this->db = new Database();
        $this->notifier = new Emails();
        $this->logFile = $logFile;
        $this->cronName = $cronName ?: self::cronNameFromLog($logFile);
        $this->startTime = time();
        $this->currentTime = jdate("H:i", '', '', 'Asia/Tehran', 'en');

        $this->cronTimes = [
            'clear-expired-transactions' => $_ENV['CRON_CLEAR_EXPIRED'] ?? '00:00',
            'update-products-statistics' => $_ENV['CRON_UPDATE_PRODUCTS'] ?? '00:05',
            'update-instructors-statistics' => $_ENV['CRON_UPDATE_INSTRUCTORS'] ?? '00:10',
        ];
    }

    final public function execute(): void
    {
        try {
            $this->db->beginTransaction();
            $this->run();
            $this->db->commit();
        } catch (Exception $e) {
            $this->failed = true;
            $this->errorMessage = $e->getMessage();
            $this->errorTrace = $e->getTraceAsString();
            $this->db->rollback();
        } finally {
            $this->finish();
        }
    }

    abstract protected function run(): void;

    protected function finish(): void
    {
        $executionTime = time() - $this->startTime;
        $dateTime = jdate('Y/m/d H:i:s', '', '', 'Asia/Tehran', 'en');

        if ($this->failed) {
            self::log($this->logFile, "Cron failed: {$this->cronName} in {$executionTime}s — {$this->errorMessage}");
            $toEmail = $_ENV['TECHNICAL_SUPPORT_MAIL'] ?? '';
            if ($toEmail !== '') {
                $subject = "❌ خطا در اجرای کرون: {$this->cronName}";
                $this->send_email(
                    $toEmail,
                    'پشتیبانی فنی',
                    $subject,
                    null,
                    null,
                    [],
                    $_ENV['SENDPULSE_CRON_ERROR_TEMPLATE_ID'],
                    [
                        'cronName' => $this->cronName,
                        'executionTime' => $executionTime,
                        'logFile' => $this->logFile,
                        'errorMessage' => $this->errorMessage,
                        'dateTime' => $dateTime,
                        'stackTrace' => $this->errorTrace
                    ]
                );
            }
        } else {
            self::log($this->logFile, "{$this->logMessage} in {$executionTime} seconds");
            $toEmail = $_ENV['TECHNICAL_SUPPORT_MAIL'] ?? '';
            if ($toEmail !== '') {
                $subject = "✅ کرون جاب موفقیت‌آمیز: {$this->cronName}";
                $this->send_email(
                    $toEmail,
                    'پشتیبانی فنی',
                    $subject,
                    null,
                    null,
                    [],
                    $_ENV['SENDPULSE_CRON_SUCCESS_TEMPLATE_ID'],
                    [
                        'cronName' => $this->cronName,
                        'executionTime' => $executionTime,
                        'logFile' => $this->logFile,
                        'dateTime' => $dateTime,
                        'logMessage' => $this->logMessage
                    ]
                );
            }
        }
    }

    protected static function log(string $logFile, string $message): void
    {
        $date = jdate('Y-m-d H:i:s', '', '', 'Asia/Tehran', 'en');
        $line = "[{$date}] {$message}\n";
        @file_put_contents(__DIR__ . "/../Logs/{$logFile}", $line, FILE_APPEND);
    }

    protected static function cronNameFromLog(string $logFile): string
    {
        $base = str_replace('.log', '', $logFile);
        $base = str_replace('-', ' ', $base);
        return trim($base) !== '' ? ucwords($base) : 'Unknown Cron';
    }
}
