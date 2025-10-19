<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Users\Users;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use DateTime;
use DateTimeZone;
use Exception;
use Sendpulse\RestApi\ApiClient;
use Sendpulse\RestApi\ApiClientException;
use Sendpulse\RestApi\Storage\FileStorage;

class Emails extends Support
{
    use Base, Sanitizer;

    public function send_email_manually($params)
    {
        $this->check_role(['admin']);

        $this->check_params($params, ['recipient_name', 'recipient_email', 'subject', 'content']);

        $result = $this->send_email($params['recipient_email'], $params['recipient_name'], $params['subject'], null, $params['content']);

        if (!$result) {
            Response::error('ایمیل ارسال نشد');
        }

        Response::success('ایمیل ارسال شد');
    }

    public function get_sent_emails($params)
    {
        $page = $params['page'] ?? 1;
        $limit = !empty($params['limit']) && $params['limit'] <= 20 ? $params['limit'] : 20;

        $offset = ($page - 1) * $limit;

        if ($limit > 100) {
            $limit = 100;
        }

        $clientId = $_ENV['SENDPULSE_CLIENT_ID'];
        $clientSecret = $_ENV['SENDPULSE_CLIENT_SECRET'];

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('خطا در دریافت اطلاعات تنظیمات');
        }

        try {
            $apiClient = new ApiClient(
                $clientId,
                $clientSecret,
                new FileStorage()
            );

            $filters = [
                'limit' => $limit,
                'offset' => $offset
            ];
            $allowedKeys = ['from', 'to', 'sender', 'recipient', 'country'];
            foreach ($allowedKeys as $key) {
                if (!empty($params[$key])) {
                    $filters[$key] = $params[$key];
                }
            }

            $emails = $apiClient->get('smtp/emails', $filters);

            if (!$emails) {
                Response::success('ایمیلی یافت نشد', [
                    'emails' => [],
                    'total' => 0,
                    'total_pages' => 1
                ]);
            }

            foreach ($emails as &$email) {
                $date_obj = new DateTime($email['send_date'], new DateTimeZone('UTC'));
                $date_obj->setTimezone(new DateTimeZone('Asia/Tehran'));
                $email['send_date'] = $date_obj->format('Y-m-d H:i:s');
            }

            $total = $this->get_total_sent_emails($filters);

            $totalPages = ceil($total / $limit);

            Response::success('لیست ایمیل های ارسالی دریافت شد', 'emailsData', [
                'emails' => $emails,
                'total' => $total,
                'total_pages' => $totalPages
            ]);

        } catch (ApiClientException $e) {
            error_log('SendPulse API Error (getSentEmails): ' . $e->getMessage() . ' | Code: ' . $e->getCode());
            throw new Exception('خطا در دریافت لیست ایمیل‌ها: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('getSentEmails Error: ' . $e->getMessage());
            Response::error('خطا در دریافت لیست ایمیل های ارسالی');
        }
    }

    public function get_total_sent_emails($params): int
    {
        $clientId = $_ENV['SENDPULSE_CLIENT_ID'];
        $clientSecret = $_ENV['SENDPULSE_CLIENT_SECRET'];

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('خطا در دریافت اطلاعات تنظیمات');
        }

        try {
            $apiClient = new ApiClient(
                $clientId,
                $clientSecret,
                new FileStorage()
            );

            $filters = [];
            $allowedKeys = ['from', 'to', 'sender', 'recipient', 'country'];
            foreach ($allowedKeys as $key) {
                if (!empty($params[$key])) {
                    $filters[$key] = $params[$key];
                }
            }

            $response = $apiClient->get('smtp/emails/total', $filters);

            if (isset($response['total']) && is_numeric($response['total'])) {
                return (int) $response['total'];
            }

            throw new Exception('پاسخ نامعتبر از API دریافت شد');

        } catch (ApiClientException $e) {
            error_log('SendPulse API Error (getTotalSentEmails): ' . $e->getMessage() . ' | Code: ' . $e->getCode());
            throw new Exception('خطا در دریافت تعداد کل ایمیل‌ها: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('getTotalSentEmails Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function send_success_cron_email(
        string $cronName,
        float $executionTime,
        string $logFile,
        string $logMessage,
        string $toEmail,
        string $toName = 'مدیر سیستم'
    ): bool {
        try {
            $date_time = jdate('Y-m-d H:i:s', '', '', 'Asia/Tehran', 'en');

            $subject = "✅ کرون جاب موفقیت‌آمیز: {$cronName}";

            $htmlContent = $this->get_success_cron_email_template(
                $cronName,
                $executionTime,
                $logFile,
                $logMessage,
                $date_time
            );

            return $this->send_email(
                $toEmail,
                $toName,
                $subject,
                $htmlContent
            );
        } catch (Exception $e) {
            Error::log('email', "خطا در ارسال ایمیل موفقیت کرون: " . $e->getMessage(), 'txt');
            return false;
        }
    }

    public function send_error_cron_email(
        string $cronName,
        float $executionTime,
        string $logFile,
        string $errorMessage,
        string $toEmail,
        string $toName = 'مدیر سیستم',
        string $stackTrace = ''
    ): bool {
        try {
            $date_time = jdate('Y-m-d H:i:s', '', '', 'Asia/Tehran', 'en');

            $subject = "❌ خطا در اجرای کرون: {$cronName}";

            $htmlContent = $this->get_error_cron_email_template(
                $cronName,
                $executionTime,
                $logFile,
                $errorMessage,
                $date_time,
                $stackTrace
            );

            return $this->send_email(
                $toEmail,
                $toName,
                $subject,
                $htmlContent
            );
        } catch (Exception $e) {
            Error::log('email', "خطا در ارسال ایمیل خطا کرون: " . $e->getMessage());
            return false;
        }
    }

    private function get_success_cron_email_template(
        string $cronName,
        float $executionTime,
        string $logFile,
        string $logMessage,
        string $dateTime
    ): string {
        return <<<HTML
    <!DOCTYPE html>
    <html lang="en" dir="ltr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Successful Cron Job Execution Report</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f5f7fa;
                    margin: 0;
                    padding: 0;
                    color: #333;
                    line-height: 1.6;
                }
                
                .email-container {
                    max-width: 700px;
                    margin: 20px auto;
                    background: white;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                    border: 1px solid #e1e4e8;
                }
                
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px 30px 20px;
                    text-align: center;
                }
                
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                    font-weight: 600;
                }
                
                .header p {
                    margin: 10px 0 0;
                    opacity: 0.9;
                    font-size: 16px;
                }
                
                .icon {
                    font-size: 48px;
                    margin-bottom: 15px;
                }
                
                .content {
                    padding: 30px;
                }
                
                .log-info {
                    background: #f8f9ff;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 25px;
                    border-left: 4px solid #667eea;
                }
                
                .info-row {
                    display: flex;
                    margin-bottom: 12px;
                    align-items: flex-start;
                }
                
                .info-label {
                    font-weight: 600;
                    width: 120px;
                    color: #4a5568;
                    font-size: 14px;
                }
                
                .info-value {
                    flex: 1;
                    color: #2d3748;
                    word-break: break-all;
                    font-size: 14px;
                }
                
                .timestamp {
                    color: #718096;
                    font-size: 12px;
                    margin-top: 5px;
                }
                
                .log-message {
                    background: #f0fff4;
                    border-radius: 8px;
                    padding: 20px;
                    border: 1px solid #c6f6d5;
                    margin-bottom: 25px;
                    font-family: 'Courier New', monospace;
                    font-size: 14px;
                    line-height: 1.5;
                    white-space: pre-wrap;
                    overflow-x: auto;
                }
                
                .status-badge {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    background: #f0fff4;
                    color: #38a169;
                }
                
                .highlight {
                    background: #e6fffa;
                    padding: 2px 4px;
                    border-radius: 3px;
                    font-weight: 500;
                    color: #319795;
                }
                
                .footer {
                    background: #edf2f7;
                    padding: 20px 30px;
                    text-align: center;
                    color: #4a5568;
                    font-size: 12px;
                    border-top: 1px solid #e2e8f0;
                }
                
                @media (max-width: 600px) {
                    .email-container {
                        margin: 10px;
                        border-radius: 8px;
                    }
                    
                    .header, .content {
                        padding: 20px;
                    }
                    
                    .info-row {
                        flex-direction: column;
                    }
                    
                    .info-label {
                        width: auto;
                        margin-bottom: 5px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <div class="icon">✅</div>
                    <h1>Successful Cron Job Execution</h1>
                    <p>Report of cron job execution in the system</p>
                </div>
                
                <div class="content">
                    <div class="log-info">
                        <div class="info-row">
                            <div class="info-label">Status:</div>
                            <div class="info-value">
                                <span class="status-badge">Success</span>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Cron Name:</div>
                            <div class="info-value"><span class="highlight">{$cronName}</span></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Execution Time:</div>
                            <div class="info-value">{$executionTime} seconds</div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Log File:</div>
                            <div class="info-value">{$logFile}</div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Date & Time:</div>
                            <div class="info-value">
                                {$dateTime}
                                <div class="timestamp">This cron job was successfully executed at the above time</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="log-message">
        {$logMessage}
                    </div>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <p style="font-size: 14px; color: #4a5568;">
                            This message has been automatically sent by the monitoring system.
                        </p>
                        <p style="font-size: 12px; color: #718096; margin-top: 5px;">
                            All rights reserved &copy; " . date('Y') . "
                        </p>
                    </div>
                </div>
                
                <div class="footer">
                    This report was sent by the platform's cron job management system.
                </div>
            </div>
        </body>
    </html>
HTML;
    }

    private function get_error_cron_email_template(
        string $cronName,
        float $executionTime,
        string $logFile,
        string $errorMessage,
        string $dateTime,
        string $stackTrace
    ): string {
        return <<<HTML
    <!DOCTYPE html>
    <html lang="en" dir="ltr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Cron Job Execution Error Report</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f5f7fa;
                    margin: 0;
                    padding: 0;
                    color: #333;
                    line-height: 1.6;
                }
                
                .email-container {
                    max-width: 700px;
                    margin: 20px auto;
                    background: white;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                    border: 1px solid #e1e4e8;
                }
                
                .header {
                    background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
                    color: white;
                    padding: 30px 30px 20px;
                    text-align: center;
                }
                
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                    font-weight: 600;
                }
                
                .header p {
                    margin: 10px 0 0;
                    opacity: 0.9;
                    font-size: 16px;
                }
                
                .icon {
                    font-size: 48px;
                    margin-bottom: 15px;
                }
                
                .content {
                    padding: 30px;
                }
                
                .log-info {
                    background: #fff5f5;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 25px;
                    border-left: 4px solid #e53e3e;
                }
                
                .info-row {
                    display: flex;
                    margin-bottom: 12px;
                    align-items: flex-start;
                }
                
                .info-label {
                    font-weight: 600;
                    width: 120px;
                    color: #4a5568;
                    font-size: 14px;
                }
                
                .info-value {
                    flex: 1;
                    color: #2d3748;
                    word-break: break-all;
                    font-size: 14px;
                }
                
                .timestamp {
                    color: #718096;
                    font-size: 12px;
                    margin-top: 5px;
                }
                
                .error-message {
                    background: #fff5f5;
                    border-radius: 8px;
                    padding: 20px;
                    border: 1px solid #fc8181;
                    margin-bottom: 25px;
                    font-family: 'Courier New', monospace;
                    font-size: 14px;
                    line-height: 1.5;
                    white-space: pre-wrap;
                    overflow-x: auto;
                }
                
                .stack-trace {
                    background: #f7fafc;
                    border-radius: 8px;
                    padding: 20px;
                    border: 1px solid #e2e8f0;
                    margin-bottom: 25px;
                    font-family: 'Courier New', monospace;
                    font-size: 12px;
                    line-height: 1.5;
                    white-space: pre-wrap;
                    overflow-x: auto;
                    max-height: 300px;
                    overflow-y: auto;
                }
                
                .status-badge {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    background: #fff5f5;
                    color: #e53e3e;
                }
                
                .highlight {
                    background: #fff5f5;
                    padding: 2px 4px;
                    border-radius: 3px;
                    font-weight: 500;
                    color: #c53030;
                }
                
                .footer {
                    background: #edf2f7;
                    padding: 20px 30px;
                    text-align: center;
                    color: #4a5568;
                    font-size: 12px;
                    border-top: 1px solid #e2e8f0;
                }
                
                .critical-alert {
                    background: #fff5f5;
                    border: 1px solid #fc8181;
                    border-radius: 8px;
                    padding: 15px;
                    margin-top: 20px;
                    color: #e53e3e;
                }
                
                @media (max-width: 600px) {
                    .email-container {
                        margin: 10px;
                        border-radius: 8px;
                    }
                    
                    .header, .content {
                        padding: 20px;
                    }
                    
                    .info-row {
                        flex-direction: column;
                    }
                    
                    .info-label {
                        width: auto;
                        margin-bottom: 5px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <div class="icon">❌</div>
                    <h1>Cron Job Execution Error</h1>
                    <p>Report of error in cron job execution</p>
                </div>
                
                <div class="content">
                    <div class="log-info">
                        <div class="info-row">
                            <div class="info-label">Status:</div>
                            <div class="info-value">
                                <span class="status-badge">Error</span>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Cron Name:</div>
                            <div class="info-value"><span class="highlight">{$cronName}</span></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Execution Time:</div>
                            <div class="info-value">{$executionTime} seconds</div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Log File:</div>
                            <div class="info-value">{$logFile}</div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Date & Time:</div>
                            <div class="info-value">
                                {$dateTime}
                                <div class="timestamp">This cron job encountered an error at the above time</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="error-message">
        {$errorMessage}
                    </div>
                    
                    <div class="stack-trace">
        {$stackTrace}
                    </div>
                    
                    <div class="critical-alert">
                        <strong>URGENT ALERT:</strong> This error requires immediate attention. Please address it as soon as possible.
                    </div>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <p style="font-size: 14px; color: #4a5568;">
                            This message has been automatically sent by the monitoring system.
                        </p>
                        <p style="font-size: 12px; color: #718096; margin-top: 5px;">
                            All rights reserved &copy; " . date('Y') . "
                        </p>
                    </div>
                </div>
                
                <div class="footer">
                    This report was sent by the platform's cron job management system.
                </div>
            </div>
        </body>
    </html>
HTML;
    }
}