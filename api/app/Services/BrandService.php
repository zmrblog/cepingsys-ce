<?php
declare(strict_types=1);

namespace App\Services;

class BrandService
{
    private array $brandConfig;
    private string $configFile;

    public function __construct()
    {
        $this->configFile = __DIR__ . '/../../storage/brand_config.json';
        $this->loadConfig();
    }

    public function getConfig(): array
    {
        return $this->brandConfig;
    }

    public function updateConfig(array $data): array
    {
        $allowedFields = [
            'system_name', 'logo_url', 'favicon', 'primary_color', 'login_bg_image',
            'footer_text', 'copyright', 'custom_css', 'custom_js',
            'org_full_name', 'org_short_name',
        ];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $this->brandConfig[$key] = is_string($value) ? trim($value) : $value;
            }
        }
        $this->saveConfig();
        return $this->brandConfig;
    }

    public function getSystemName(): string
    {
        return $this->brandConfig['system_name'] ?? '年度考核民主测评系统';
    }

    public function getPrimaryColor(): string
    {
        return $this->brandConfig['primary_color'] ?? '#1B5E9B';
    }

    public function getLogoUrl(): string
    {
        return $this->brandConfig['logo_url'] ?? '';
    }

    public function getLoginBgImage(): string
    {
        return $this->brandConfig['login_bg_image'] ?? '';
    }

    public function getFooterText(): string
    {
        return $this->brandConfig['footer_text'] ?? '';
    }

    public function getCustomCss(): string
    {
        return $this->brandConfig['custom_css'] ?? '';
    }

    public function getCustomJs(): string
    {
        return $this->brandConfig['custom_js'] ?? '';
    }

    public function resetToDefault(): array
    {
        $this->brandConfig = $this->defaultConfig();
        $this->saveConfig();
        return $this->brandConfig;
    }

    public function exportConfig(): string
    {
        return json_encode($this->brandConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function importConfig(string $jsonString): bool
    {
        $data = json_decode($jsonString, true);
        if (!$data || !is_array($data)) return false;
        $this->brandConfig = array_merge($this->defaultConfig(), $data);
        $this->saveConfig();
        return true;
    }

    private function loadConfig(): void
    {
        if (file_exists($this->configFile)) {
            $content = file_get_contents($this->configFile);
            $data = json_decode($content, true);
            if (is_array($data)) {
                $this->brandConfig = array_merge($this->defaultConfig(), $data);
                return;
            }
        }
        $this->brandConfig = $this->defaultConfig();
    }

    private function saveConfig(): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($this->configFile, json_encode($this->brandConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function defaultConfig(): array
    {
        return [
            'system_name' => '年度考核民主测评系统',
            'logo_url' => '',
            'favicon' => '',
            'primary_color' => '#1B5E9B',
            'login_bg_image' => '',
            'footer_text' => '',
            'copyright' => '',
            'custom_css' => '',
            'custom_js' => '',
            'org_full_name' => '',
            'org_short_name' => '',
        ];
    }
}
