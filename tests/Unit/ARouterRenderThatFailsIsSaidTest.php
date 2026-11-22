<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared router's nginx and DNS files in bin/semitexa
 * (tk-router-render-failures-are-silent). router_generate_nginx_config and
 * router_generate_dns_config wrote straight over the files the router reads
 * and checked nothing: a render that stopped half way (a full disk) left a cut
 * config in place, and the registration that asked for it reported success and
 * restarted the proxy onto it. Now each file is rendered beside its target and
 * put in place only when all of it was written; a render that failed says so
 * and returns 1, and the callers neither reload nor report success.
 */
final class ARouterRenderThatFailsIsSaidTest extends TestCase
{
    private const NGINX_SAID = "WARN Could not write the shared router's nginx config in %s/router/nginx/generated: the router keeps the config it had, "
        . 'without what was registered or moved since. Free space there or make it writable, then run this again.';

    private const DNS_SAID = "WARN Could not write the shared router's DNS config in %s/router/dns/generated: the router keeps the config it had, "
        . 'without what was registered or removed since. Free space there or make it writable, then run this again.';

    /** A disk that fills during the render: cat writes the start of a file and fails. */
    private const CAT_ON_A_FULL_DISK = 'cat() { if [ "$#" -eq 0 ]; then printf "server { cut"; return 1; fi; command cat "$@"; }' . "\n";

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-router-render-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/router/registry/domains', 0o777, true);
        mkdir($this->tmp . '/router/nginx/generated', 0o777, true);
        mkdir($this->tmp . '/router/dns/generated', 0o777, true);

        // What the router reads now, from the render before.
        file_put_contents($this->tmp . '/router/nginx/generated/default.conf', "# the default before\n");
        file_put_contents($this->tmp . '/router/nginx/generated/domains.conf', "# the routes before\n");
        file_put_contents($this->tmp . '/router/dns/generated/dnsmasq.conf', "# the dnsmasq before\n");
        file_put_contents($this->tmp . '/router/dns/generated/domains.conf', "# the names before\n");
        file_put_contents($this->tmp . '/router/docker-compose.yml', "services: {}\n");
        file_put_contents(
            $this->tmp . '/router/registry/domains/shop.test.env',
            "DOMAIN=shop.test\nPROJECT_ROOT=/home/dev/shop\nCOMPOSE_PROJECT_NAME=shop\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9501\nMODE=hosts\n",
        );

        $functions = '';
        foreach ([
            'router_ensure_dirs', 'registry_entry_load', 'router_validate_domain', 'router_port_normalize', 'router_route_admit',
            'router_render', 'router_apply_app_port_to_domains', 'router_start_for_mode',
        ] as $name) {
            $functions .= self::function($name) . "\n";
        }
        // The renders and what puts a rendered file in place. As a region:
        // their here-documents hold a "}" at the start of a line.
        $functions .= self::region('router_generate_nginx_config() {', 'router_render() {') . "\n";

        file_put_contents($this->tmp . '/router.sh', 'T=' . escapeshellarg($this->tmp) . "\n"
            . 'ROUTER_ROOT="$T/router"; ROUTER_COMPOSE="$ROUTER_ROOT/docker-compose.yml"; ROUTER_REGISTRY_DIR="$ROUTER_ROOT/registry/domains"' . "\n"
            . 'ROUTER_NGINX_DIR="$ROUTER_ROOT/nginx/generated"; ROUTER_DNS_DIR="$ROUTER_ROOT/dns/generated"' . "\n"
            . 'ROUTER_NGINX_DEFAULT="$ROUTER_NGINX_DIR/default.conf"; ROUTER_NGINX_DOMAINS="$ROUTER_NGINX_DIR/domains.conf"' . "\n"
            . 'ROUTER_DNSMASQ_CONF="$ROUTER_DNS_DIR/dnsmasq.conf"; ROUTER_DNS_DOMAINS="$ROUTER_DNS_DIR/domains.conf"' . "\n"
            . 'PROJECT_ROOT=/home/dev/shop' . "\n"
            . 'warn() { printf "WARN %s\n" "$*"; }; router_proxy_networks() { :; }' . "\n"
            // On a descriptor of its own: the callers send what compose prints to /dev/null.
            . 'exec 3>&1; router_compose() { echo "compose $*" >&3; }' . "\n"
            . $functions);
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    #[Test]
    public function a_render_writes_over_the_files_the_router_has_mounted(): void
    {
        // The router's containers mount each file by itself: a file moved in
        // is a new one, which they would not see before a restart.
        $inode = fileinode($this->tmp . '/router/nginx/generated/domains.conf');
        $out = $this->shell('router_generate_nginx_config; echo "nginx=$?"; router_generate_dns_config; echo "dns=$?"');

        self::assertSame(['nginx=0', 'dns=0'], $out);
        self::assertStringContainsString('server_name shop.test;', $this->read('nginx/generated/domains.conf'));
        self::assertStringContainsString('proxy_pass http://host.docker.internal:9501;', $this->read('nginx/generated/domains.conf'));
        self::assertStringContainsString('listen 80 default_server;', $this->read('nginx/generated/default.conf'));
        self::assertStringContainsString('conf-file=/etc/dnsmasq.d/domains.conf', $this->read('dns/generated/dnsmasq.conf'));
        self::assertSame("address=/shop.test/127.0.0.1\n", $this->read('dns/generated/domains.conf'));
        self::assertSame($inode, fileinode($this->tmp . '/router/nginx/generated/domains.conf'), 'written over, not moved onto');
        $this->assertNothingIsLeftBeside();
    }

    #[Test]
    public function an_nginx_render_cut_short_keeps_the_config_before_and_says_so(): void
    {
        $out = $this->shell(self::CAT_ON_A_FULL_DISK . 'router_generate_nginx_config; echo "status=$?"; echo "root=$PROJECT_ROOT"');

        self::assertSame([sprintf(self::NGINX_SAID, $this->tmp), 'status=1', 'root=/home/dev/shop'], $out);
        $this->assertTheConfigBeforeIsIntact();
    }

    /** The same for the names: the disk fills at the line of a domain. */
    #[Test]
    public function a_dns_render_cut_short_keeps_the_config_before_and_says_so(): void
    {
        $out = $this->shell(
            'printf() { case "$1" in address=*) command printf "address=/sho"; return 1 ;; esac; command printf "$@"; }' . "\n"
            . 'router_generate_dns_config; echo "status=$?"',
        );

        self::assertSame([sprintf(self::DNS_SAID, $this->tmp), 'status=1'], $out);
        $this->assertTheConfigBeforeIsIntact();
    }

    /** The render fitted beside the file and the write over the file itself did not: what it held is put back. */
    #[Test]
    public function a_write_over_the_file_that_fails_puts_back_what_it_held(): void
    {
        $out = $this->shell(
            'cat() { case "${1:-}" in *domains.conf.new.*) printf "server { cut"; return 1 ;; esac; command cat "$@"; }' . "\n"
            . 'router_generate_nginx_config; echo "status=$?"',
        );

        self::assertSame([sprintf(self::NGINX_SAID, $this->tmp), 'status=1'], $out);
        self::assertSame("# the routes before\n", $this->read('nginx/generated/domains.conf'));
        $this->assertNothingIsLeftBeside();
    }

    /** The finding: the port was recorded, the render failed, and the registration heard nothing of it. */
    #[Test]
    public function a_registration_hears_that_the_routes_did_not_follow_the_port(): void
    {
        $registration = 'router_registry_write_entry() { echo "entry $1 -> $2"; }' . "\n"
            . 'if router_apply_app_port_to_domains /home/dev/shop 9502; then echo "routes follow"; else echo "routes did not follow"; fi';

        self::assertSame(
            ['entry shop.test -> 9502', sprintf(self::NGINX_SAID, $this->tmp), 'routes did not follow'],
            $this->shell(self::CAT_ON_A_FULL_DISK . $registration),
            'and the proxy is not restarted',
        );
        $this->assertTheConfigBeforeIsIntact();

        // The same registration when the render works.
        self::assertSame(['entry shop.test -> 9502', 'compose restart proxy', 'routes follow'], $this->shell($registration));
    }

    /** The compose file is rendered as the others: cut short, the one before stays and the router is not started from it. */
    #[Test]
    public function a_compose_render_cut_short_keeps_the_file_before_and_the_router_is_not_started(): void
    {
        $said = "WARN Could not write the shared router's compose file " . $this->tmp . '/router/docker-compose.yml: the router keeps the one it had '
            . 'and is not started or restarted. Free space there or make it writable, then run this again.';
        $start = 'router_mode_get() { echo hosts; }; router_check_before_rebind() { :; }' . "\n"
            . 'router_ensure_loopback_network() { :; }; router_reconnect_all() { :; }' . "\n"
            . 'if ! router_start_for_mode; then echo "not started"; fi';

        self::assertSame(
            [$said, 'status=1'],
            $this->shell(self::CAT_ON_A_FULL_DISK . 'router_generate_compose; echo "status=$?"'),
        );
        self::assertSame("services: {}\n", $this->read('docker-compose.yml'));
        // The render of all three stops there, and so does a start.
        self::assertSame([$said, 'rendered=1'], $this->shell(self::CAT_ON_A_FULL_DISK . 'router_render; echo "rendered=$?"'));
        self::assertSame([$said, 'not started'], $this->shell(self::CAT_ON_A_FULL_DISK . $start));
        self::assertSame("services: {}\n", $this->read('docker-compose.yml'));
        $this->assertTheConfigBeforeIsIntact();
        self::assertSame(['dns', 'docker-compose.yml', 'nginx', 'registry'], $this->names(''), 'nothing left beside the compose file');
    }

    /** server:start asks in a test, where set -e does not stop on a failed render. */
    #[Test]
    public function a_router_start_stops_before_compose_when_the_render_failed(): void
    {
        $start = 'router_mode_get() { echo hosts; }; router_check_before_rebind() { :; }; router_generate_compose() { :; }' . "\n"
            . 'router_ensure_loopback_network() { :; }; router_reconnect_all() { :; }' . "\n"
            . 'if ! router_start_for_mode; then echo "not started"; fi';

        self::assertSame([sprintf(self::NGINX_SAID, $this->tmp), 'not started'], $this->shell(self::CAT_ON_A_FULL_DISK . $start));
        $this->assertTheConfigBeforeIsIntact();

        self::assertSame(['compose up -d proxy', 'compose stop dns', 'compose restart proxy'], $this->shell($start));
    }

    private function assertTheConfigBeforeIsIntact(): void
    {
        self::assertSame("# the default before\n", $this->read('nginx/generated/default.conf'));
        self::assertSame("# the routes before\n", $this->read('nginx/generated/domains.conf'));
        self::assertSame("# the dnsmasq before\n", $this->read('dns/generated/dnsmasq.conf'));
        self::assertSame("# the names before\n", $this->read('dns/generated/domains.conf'));
        $this->assertNothingIsLeftBeside();
    }

    private function assertNothingIsLeftBeside(): void
    {
        self::assertSame(['default.conf', 'domains.conf'], $this->names('nginx/generated'));
        self::assertSame(['dnsmasq.conf', 'domains.conf'], $this->names('dns/generated'));
    }

    /** @return list<string> */
    private function names(string $dir): array
    {
        $names = array_values(array_diff(scandir($this->tmp . '/router/' . $dir) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->tmp . '/router/' . $file);
    }

    /** @return list<string> the lines printed */
    private function shell(string $snippet): array
    {
        $sh = '. ' . escapeshellarg($this->tmp . '/router.sh') . "\n" . $snippet;
        $raw = (string) shell_exec('env -i PATH=/usr/bin:/bin ' . self::sh() . ' -c ' . escapeshellarg($sh) . ' 2>&1');

        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    /** The shell under test: sh, or the one SEMITEXA_TEST_SH names (dash, bash, "busybox sh"). */
    private static function sh(): string
    {
        $sh = getenv('SEMITEXA_TEST_SH');

        return \is_string($sh) && preg_match('/^[a-z]+( sh)?$/', $sh) === 1 ? $sh : 'sh';
    }

    private static function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n.*?^\}$/ms', self::script(), $m), $name . ' must still exist in bin/semitexa');

        return $m[0] ?? '';
    }

    private static function region(string $from, string $to): string
    {
        $script = self::script();
        $start = strpos($script, "\n" . $from);
        $end = strpos($script, "\n" . $to);
        self::assertTrue($start !== false && $end !== false && $start < $end, $from . ' must still come before ' . $to . ' in bin/semitexa');

        return substr($script, (int) $start, (int) $end - (int) $start);
    }

    private static function script(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/scaffold/bin/semitexa');
    }
}
