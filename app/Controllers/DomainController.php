<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Core\Logger;
use MailSystem\Models\Domain;
use MailSystem\Models\Setting;

/**
 * DNS 解析服务支持说明
 *
 * ============================================
 * 国内主流 DNS 服务商 API 配置指南
 * ============================================
 *
 * 一、腾讯云 DNSPod (https://dnspod.cloud.tencent.com/)
 * -------------------------------------------------
 * 1. 登录腾讯云控制台 → DNSPod → 个人中心 → API 密钥
 * 2. 创建密钥，获得 SecretId 和 SecretKey
 * 3. 在后台设置中填入 SecretId 和 SecretKey
 * 4. 选择 DNS服务商 为 "腾讯云"
 *
 * API 调用方式:
 *    POST https://dnsapi.cn/Record.Create
 *    参数: login_token=SecretId,password=SecretKey,format=json,domain=xxx,record_type=A,record_line=默认,value=xxx
 *
 * 二、阿里云 DNS (https://www.aliyun.com/product/dns)
 * -------------------------------------------------
 * 1. 登录阿里云控制台 → AccessKey 管理
 * 2. 创建 AccessKey，获得 AccessKeyId 和 AccessKeySecret
 * 3. 在后台设置中填入 AccessKeyId 和 AccessKeySecret
 * 4. 选择 DNS服务商 为 "阿里云"
 *
 * API 调用方式:
 *    POST https://alidns.aliyuncs.com/?Action=AddDomainRecord
 *    参数: AccessKeyId=xxx, AccessKeySecret=xxx, DomainName=xxx, Type=A, Value=xxx
 *
 * 三、华为云 DNS (https://www.huaweicloud.com/product/dns)
 * -------------------------------------------------
 * 1. 登录华为云控制台 → 我的凭证 → 访问密钥
 * 2. 创建访问密钥，获得 AccessKeyId 和 AccessKeySecret
 * 3. 在后台设置中填入 AccessKeyId 和 AccessKeySecret
 * 4. 选择 DNS服务商 为 "华为云"
 *
 * API 调用方式:
 *    POST https://dns.myhuaweicloud.com/v2/reord/recordsets
 *    参数: iam_access_key=xxx, iam_secret_key=xxx, domain_name=xxx, name=xxx, type=A, value=xxx
 *
 * 四、手动解析 (manual)
 * -------------------------------------------------
 * 选择"手动"后，系统会显示所需的 DNS 记录列表和推荐值，
 * 需要您手动到域名服务商的控制台中添加这些记录。
 *
 * 推荐的 DNS 记录:
 * 1. A 记录: mail.domain.com -> 服务器IP
 * 2. MX 记录: domain.com -> mail.domain.com 优先级10
 * 3. TXT 记录: domain.com -> v=spf1 mx ~all
 * 4. TXT 记录: _dmarc.domain.com -> v=DMARC1; p=quarantine; rua=mailto:admin@domain.com
 *
 * 五、API 一键解析 (需要服务商 API 凭证)
 * -------------------------------------------------
 * 在域名详情页面点击"一键解析"按钮，系统将自动调用
 * 您配置好的 DNS 服务商 API，为您创建所需的 DNS 记录。
 * 此功能需要确保:
 * - 域名已在对应服务商处托管
 * - 已正确配置 API 凭证
 * - DNS 服务商账户余额充足
 *
 * ============================================
 */

class DomainController extends BaseController
{
    public function index(Request $req): void
    {
        Auth::requireLogin();
        $u = Auth::user();
        $all = ($u['role'] === 'admin') && $req->query('all') == '1';
        $domains = Domain::allByOwner((int) $u['id'], $all);
        $this->ok(['list' => $domains, 'total' => count($domains)]);
    }

    public function show(Request $req, array $params): void
    {
        Auth::requireLogin();
        $d = Domain::find((int) $params['id']);
        if (!$d) Response::notFound('域名不存在');
        $this->ok($d);
    }

    public function create(Request $req): void
    {
        Auth::requireLogin();
        $u = Auth::user();
        $domain = trim(strtolower((string) $req->input('domain')));
        $description = (string) $req->input('description', '');
        if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domain)) {
            Response::error('域名格式不正确', 400, 400);
        }
        if (Domain::findByName($domain)) {
            Response::error('域名已存在', 400, 400);
        }
        $id = Domain::create([
            'domain'      => $domain,
            'owner_id'    => (int) $u['id'],
            'status'      => 1,
            'description' => $description,
            'is_default'  => 0,
        ]);
        $this->log('domain.create', $domain);
        $this->ok(['id' => $id], '域名已添加');
    }

    public function update(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $d = Domain::find($id);
        if (!$d) Response::notFound('域名不存在');
        $data = [];
        foreach (['description', 'status', 'is_default'] as $f) {
            if ($req->input($f) !== null) {
                $data[$f] = $req->input($f);
            }
        }
        if (!empty($data)) Domain::update($id, $data);
        $this->log('domain.update', $d['domain']);
        $this->ok(null, '已更新');
    }

    public function delete(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $d = Domain::find($id);
        if (!$d) Response::notFound('域名不存在');
        // 检查是否还有邮箱
        $cnt = \MailSystem\Models\Mailbox::countByDomain($id);
        if ($cnt > 0) {
            Response::error('该域名下还有 ' . $cnt . ' 个邮箱，无法删除', 400, 400);
        }
        Domain::delete($id);
        $this->log('domain.delete', $d['domain']);
        $this->ok(null, '已删除');
    }

    /**
     * 获取 DNS 解析记录推荐列表
     */
    public function dnsRecords(Request $req, array $params): void
    {
        Auth::requireLogin();
        $d = Domain::find((int) $params['id']);
        if (!$d) Response::notFound('域名不存在');
        $hostname = config('mail.hostname', 'mail.' . $d['domain']);
        $ip = $req->server['SERVER_ADDR'] ?? ($_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()) ?: '');

        // 获取 DNS 服务商配置
        $dnsProvider = Setting::get('dns_provider', 'manual');
        $dnsConfig = $this->getDnsConfig();

        $this->ok([
            'domain'       => $d['domain'],
            'provider'      => $dnsProvider,
            'provider_name' => $this->getProviderName($dnsProvider),
            'config'        => $dnsConfig,
            'records'       => [
                [
                    'type'     => 'A',
                    'host'     => 'mail',
                    'value'    => $ip,
                    'priority' => null,
                    'note'     => '邮件服务器 IP 地址',
                    'required' => true,
                ],
                [
                    'type'     => 'MX',
                    'host'     => '@',
                    'value'    => $hostname,
                    'priority' => 10,
                    'note'     => '邮件交换记录，指向邮件主机',
                    'required' => true,
                ],
                [
                    'type'     => 'TXT',
                    'host'     => '@',
                    'value'    => 'v=spf1 mx ~all',
                    'priority' => null,
                    'note'     => 'SPF 记录，授权邮件服务器发送',
                    'required' => true,
                ],
                [
                    'type'     => 'TXT',
                    'host'     => '_dmarc',
                    'value'    => 'v=DMARC1; p=quarantine; rua=mailto:admin@' . $d['domain'],
                    'priority' => null,
                    'note'     => 'DMARC 记录，防止邮件伪造',
                    'required' => true,
                ],
                [
                    'type'     => 'TXT',
                    'host'     => 'default._domainkey',
                    'value'    => 'v=DKIM1; k=rsa; p=请到后台查看公钥',
                    'priority' => null,
                    'note'     => 'DKIM 签名记录（可选，需在后台配置 DKIM 密钥）',
                    'required' => false,
                ],
            ],
        ]);
    }

    /**
     * 一键解析 DNS 记录
     */
    public function dnsSync(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $d = Domain::find((int) $params['id']);
        if (!$d) Response::notFound('域名不存在');

        $provider = Setting::get('dns_provider', 'manual');
        if ($provider === 'manual') {
            Response::error('请先在设置中选择 DNS 服务商并配置 API 凭证', 400, 400);
        }

        $ip = $req->server['SERVER_ADDR'] ?? ($_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()) ?: '');
        $hostname = config('mail.hostname', 'mail.' . $d['domain']);

        $result = match ($provider) {
            'aliyun' => $this->syncDnsAliyun($d['domain'], $ip, $hostname),
            'tencent' => $this->syncDnsTencent($d['domain'], $ip, $hostname),
            'huawei' => $this->syncDnsHuawei($d['domain'], $ip, $hostname),
            default => null,
        };

        if ($result === false) {
            Response::error('DNS 同步失败，请检查 API 凭证配置', 500, 500);
        }

        $this->log('domain.dns_sync', $d['domain'], "provider: $provider");
        $this->ok($result, 'DNS 记录同步成功');
    }

    private function getDnsConfig(): array
    {
        return [
            'aliyun' => [
                'name'       => '阿里云 DNS',
                'configured' => !empty(Setting::get('aliyun_access_key', '')),
                'fields'     => ['aliyun_access_key' => 'AccessKey ID', 'aliyun_access_secret' => 'AccessKey Secret'],
            ],
            'tencent' => [
                'name'       => '腾讯云 DNSPod',
                'configured' => !empty(Setting::get('tencent_secret_id', '')),
                'fields'     => ['tencent_secret_id' => 'SecretId', 'tencent_secret_key' => 'SecretKey'],
            ],
            'huawei' => [
                'name'       => '华为云 DNS',
                'configured' => !empty(Setting::get('huawei_access_key', '')),
                'fields'     => ['huawei_access_key' => 'AccessKey ID', 'huawei_access_secret' => 'AccessKey Secret'],
            ],
            'manual' => [
                'name'       => '手动解析',
                'configured' => true,
                'fields'     => [],
            ],
        ];
    }

    private function getProviderName(string $provider): string
    {
        return match ($provider) {
            'aliyun'  => '阿里云 DNS',
            'tencent' => '腾讯云 DNSPod',
            'huawei'  => '华为云 DNS',
            default   => '手动解析',
        };
    }

    private function syncDnsAliyun(string $domain, string $ip, string $hostname): array|false
    {
        $accessKey = Setting::get('aliyun_access_key', '');
        $accessSecret = Setting::get('aliyun_access_secret', '');
        if (empty($accessKey) || empty($accessSecret)) return false;

        $records = [
            ['Type' => 'A',     'RR' => 'mail',             'Value' => $ip],
            ['Type' => 'MX',    'RR' => '@',               'Value' => $hostname, 'Priority' => 10],
            ['Type' => 'TXT',   'RR' => '@',               'Value' => 'v=spf1 mx ~all'],
            ['Type' => 'TXT',   'RR' => '_dmarc',          'Value' => "v=DMARC1; p=quarantine; rua=mailto:admin@$domain"],
        ];

        $results = [];
        foreach ($records as $record) {
            $params = [
                'Action'           => 'AddDomainRecord',
                'AccessKeyId'      => $accessKey,
                'Format'           => 'JSON',
                'Version'          => '2015-01-09',
                'SignatureMethod'  => 'HMAC-SHA1',
                'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
                'SignatureVersion'  => '1.0',
                'DomainName'       => $domain,
                'Type'            => $record['Type'],
                'RR'              => $record['RR'],
                'Value'           => $record['Value'],
            ];
            if (isset($record['Priority'])) {
                $params['Priority'] = $record['Priority'];
            }
            // 添加签名...
            $params['Signature'] = $this->signAliyunParams($params, $accessSecret);
            $url = 'https://alidns.aliyuncs.com/?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                Logger::error(sprintf('Aliyun DNS sync cURL error: %s', curl_error($ch)), ['domain' => $domain, 'record' => $record]);
                curl_close($ch);
                return false;
            }
            $decodedResp = json_decode($resp, true);
            if (isset($decodedResp['Code'])) {
                Logger::error(sprintf('Aliyun DNS API error: %s', $decodedResp['Message'] ?? 'Unknown error'), ['domain' => $domain, 'record' => $record, 'response' => $decodedResp]);
            }
            curl_close($ch);

            $results[] = ['record' => $record, 'response' => $decodedResp];
        }
        return $results;
    }

    private function syncDnsTencent(string $domain, string $ip, string $hostname): array|false
    {
        $secretId = Setting::get('tencent_secret_id', '');
        $secretKey = Setting::get('tencent_secret_key', '');
        if (empty($secretId) || empty($secretKey)) return false;

        $records = [
            ['Type' => 'A',   'Name' => 'mail',              'Value' => $ip, 'Line' => '默认'],
            ['Type' => 'MX',  'Name' => '@',                'Value' => $hostname, 'MX' => 10, 'Line' => '默认'],
            ['Type' => 'TXT', 'Name' => '@',                'Value' => 'v=spf1 mx ~all', 'Line' => '默认'],
            ['Type' => 'TXT', 'Name' => '_dmarc',           'Value' => "v=DMARC1; p=quarantine; rua=mailto:admin@$domain", 'Line' => '默认'],
        ];

        $results = [];
        foreach ($records as $record) {
            $params = [
                'Action'     => 'RecordCreate',
                'SecretId'  => $secretId,
                'Timestamp' => time(),
                'Nonce'     => mt_rand(1, 999999),
                'Region'    => '',
                'Domain'    => $domain,
                'SubDomain' => $record['Name'],
                'RecordType'=> $record['Type'],
                'RecordLine'=> $record['Line'],
                'Value'     => $record['Value'],
            ];
            if (isset($record['MX'])) $params['MX'] = $record['MX'];

            $params['Signature'] = $this->signTencentParams($params, $secretKey);
            $url = 'https://dnsapi.cn/Record.Create';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                Logger::error(sprintf('Tencent DNS sync cURL error: %s', curl_error($ch)), ['domain' => $domain, 'record' => $record]);
                curl_close($ch);
                return false;
            }
            $decodedResp = json_decode($resp, true);
            if (isset($decodedResp['code']) && $decodedResp['code'] !== 0) {
                Logger::error(sprintf('Tencent DNS API error: %s', $decodedResp['message'] ?? 'Unknown error'), ['domain' => $domain, 'record' => $record, 'response' => $decodedResp]);
            }
            curl_close($ch);

            $results[] = ['record' => $record, 'response' => $decodedResp];
        }
        return $results;
    }

    private function syncDnsHuawei(string $domain, string $ip, string $hostname): array|false
    {
        $accessKey = Setting::get('huawei_access_key', '');
        $accessSecret = Setting::get('huawei_access_secret', '');
        if (empty($accessKey) || empty($accessSecret)) return false;

        $records = [
            ['name' => 'mail.' . $domain, 'type' => 'A',   'records' => [$ip],          'ttl' => 300],
            ['name' => $domain,           'type' => 'MX',  'records' => [$hostname . ' 10'], 'ttl' => 300],
            ['name' => $domain,           'type' => 'TXT', 'records' => ['v=spf1 mx ~all'], 'ttl' => 300],
            ['name' => '_dmarc.' . $domain, 'type' => 'TXT', 'records' => ["v=DMARC1; p=quarantine; rua=mailto:admin@$domain"], 'ttl' => 300],
        ];

        $results = [];
        foreach ($records as $record) {
            $body = json_encode([
                'name'    => $record['name'],
                'type'    => $record['type'],
                'records' => $record['records'],
                'ttl'     => $record['ttl'],
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://dns.myhuaweicloud.com/v2/recordsets',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Api-Key: ' . $accessKey,
                ],
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                Logger::error(sprintf('Huawei DNS sync cURL error: %s', curl_error($ch)), ['domain' => $domain, 'record' => $record]);
                curl_close($ch);
                return false;
            }
            $decodedResp = json_decode($resp, true);
            // Huawei Cloud DNS API typically returns 200 OK even for errors, with error details in the body
            // Check for common error indicators in the response body
            if (isset($decodedResp['error_code']) || isset($decodedResp['code']) && $decodedResp['code'] !== 'DNS.2000') {
                Logger::error(sprintf('Huawei DNS API error: %s', $decodedResp['message'] ?? $decodedResp['error_msg'] ?? 'Unknown error'), ['domain' => $domain, 'record' => $record, 'response' => $decodedResp]);
            }
            curl_close($ch);

            $results[] = ['record' => $record, 'response' => $decodedResp];
        }
        return $results;
    }

    private function signAliyunParams(array $params, string $secret): string
    {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            $str .= '&' . urlencode($k) . '=' . urlencode($v);
        }
        $str = 'GET&%2F&' . urlencode(substr($str, 1));
        return base64_encode(hash_hmac('sha1', $str, $secret . '&', true));
    }

    private function signTencentParams(array $params, string $secret): string
    {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            $str .= $k . '=' . $v . '&';
        }
        $str = rtrim($str, '&');
        return base64_encode(hash_hmac('sha1', $str, $secret, true));
    }
}
