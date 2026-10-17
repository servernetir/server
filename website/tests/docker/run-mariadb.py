#!/usr/bin/env python3
"""Run the opt-in concurrency suite against a disposable, private MariaDB."""
import argparse
import json
import secrets
import subprocess
import tempfile
import time
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--checkout', type=Path, required=True, help='Isolated repository with installed website/vendor')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--image', default='servernet-tests:php84')
    parser.add_argument('--suite', choices=['AiReservationMariaDbStressTest','IdentityMariaDbStressTest','IdentityApiTest'], default='AiReservationMariaDbStressTest')
    args = parser.parse_args()
    checkout = args.checkout.resolve(strict=True)
    app = checkout / 'website'
    if any(p.name != '.env.example' for p in app.glob('.env*')) or (app / 'bootstrap/cache/config.php').exists():
        raise SystemExit('Refusing environment files or cached configuration in test checkout')
    if not (app / 'vendor/phpunit/phpunit/phpunit').is_file():
        raise SystemExit('Install Composer dependencies in the isolated checkout first')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    name = 'servernet-stress-' + secrets.token_hex(5)
    database = name + '-db'
    client = name + '-php'
    created = []

    def docker(*command, **kwargs):
        return subprocess.run(['docker', *command], check=True, text=True, **kwargs)

    try:
        docker('network', 'create', '--internal', '--label', 'servernet.test=reservation-stress', name, capture_output=True)
        created.append(('network', name))
        with tempfile.TemporaryDirectory(prefix=name) as tmp:
            password = secrets.token_hex(24)
            db_env = Path(tmp) / 'db.env'
            db_env.write_text('MARIADB_RANDOM_ROOT_PASSWORD=1\nMARIADB_DATABASE=servernet_stress\n'
                              'MARIADB_USER=stress\nMARIADB_PASSWORD=' + password + '\n')
            db_env.chmod(0o600)
            docker('create', '--name', database, '--network', name, '--network-alias', 'db',
                   '--tmpfs', '/var/lib/mysql:rw,size=512m', '--env-file', str(db_env),
                   'mariadb:11.8', capture_output=True)
            created.append(('container', database))
            docker('start', database, capture_output=True)
            for attempt in range(60):
                ready = subprocess.run(['docker', 'exec', database, 'healthcheck.sh', '--connect', '--innodb_initialized'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                if ready.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Disposable MariaDB did not become ready')

            app_env = Path(tmp) / 'app.env'
            app_env.write_text('\n'.join([
                'APP_ENV=testing', 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'APP_URL=http://localhost', 'DB_CONNECTION=mariadb', 'DB_HOST=db', 'DB_PORT=3306',
                'DB_DATABASE=servernet_stress', 'DB_USERNAME=stress', 'DB_PASSWORD=' + password,
                'DB_URL=', 'AI_RESERVATION_STRESS=1', 'MAIL_MAILER=array', 'QUEUE_CONNECTION=sync',
                'CACHE_STORE=array', 'SESSION_DRIVER=array', 'EXCHANGE_ENABLED=false', 'STRESS_REPORT_DIR=/results',
            ]) + '\n')
            app_env.chmod(0o600)
            docker('create', '--name', client, '--network', name, '--env-file', str(app_env),
                   '--mount', f'type=bind,src={checkout},dst=/workspace,readonly',
                   '--mount', f'type=bind,src={output},dst=/results',
                   '--tmpfs', '/workspace/website/storage:rw', '--tmpfs', '/workspace/website/bootstrap/cache:rw',
                   '--workdir', '/workspace/website', args.image, 'sh', '-c',
                   'mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs storage/app bootstrap/cache && '
                   'php -v && php -d memory_limit=1024M vendor/phpunit/phpunit/phpunit '
                   '--do-not-cache-result --log-junit /results/mariadb.xml tests/Feature/'+args.suite+'.php',
                   capture_output=True)
            created.append(('container', client))
            with (output / 'mariadb.log').open('w') as log:
                subprocess.run(['docker', 'start', '--attach', client], stdout=log, stderr=subprocess.STDOUT)
            state = json.loads(docker('inspect', client, capture_output=True).stdout)[0]
            exit_code = state['State']['ExitCode']
            print(json.dumps({'exit': exit_code, 'network_internal': True, 'published_ports': False,
                              'database': 'servernet_stress', 'results': str(output)}))
            return exit_code
    finally:
        for kind, resource in reversed(created):
            command = ['docker', 'rm', '-f', resource] if kind == 'container' else ['docker', 'network', 'rm', resource]
            subprocess.run(command, check=True, stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    raise SystemExit(main())
