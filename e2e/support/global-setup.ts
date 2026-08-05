import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// package.json declares "type": "module", so __dirname does not exist.
const here = path.dirname(fileURLToPath(import.meta.url))

/**
 * Puts the database into a known state once, before the whole run.
 *
 * Its OWN database (`droidshop_e2e`), never the developer's. `migrate:fresh`
 * on the local database would wipe whatever they had been clicking through in
 * the demo — recoverable in principle, infuriating in practice, and a test
 * suite has no business destroying data to run.
 *
 * Once, not between tests: seeding costs seconds and the scenarios create
 * whatever they specifically need themselves.
 */
export const E2E_DATABASE = process.env.E2E_DATABASE ?? 'droidshop_e2e'

export default async function globalSetup(): Promise<void> {
  const root = path.resolve(here, '../..')

  const env = {
    ...process.env,
    APP_ENV: 'local',
    DB_DATABASE: E2E_DATABASE,
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    PLATFORM_DOMAIN: process.env.E2E_HOST ?? 'droidshop',
  }

  const artisan = (args: string[]): void => {
    execFileSync('php', ['artisan', ...args], { cwd: root, env, stdio: 'inherit' })
  }

  // A database name cannot be a bound parameter, so it is validated rather
  // than escaped. The value comes from our own config, but "it is trusted
  // today" is how injection gets in tomorrow.
  if (!/^[A-Za-z0-9_]+$/.test(E2E_DATABASE)) {
    throw new Error(`refusing to use [${E2E_DATABASE}] as a database name`)
  }

  // The database may not exist yet on a fresh checkout. Created through
  // tinker rather than `php -r`: config/database.php calls env(), which only
  // exists once the framework has booted, and tinker does not connect until
  // something actually queries.
  const createDatabase = [
    "$c = config('database.connections.mysql');",
    '$pdo = new PDO("mysql:host={$c[\'host\']};port={$c[\'port\']}", $c[\'username\'], $c[\'password\']);',
    `$pdo->exec('CREATE DATABASE IF NOT EXISTS ${E2E_DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');`,
  ].join(' ')

  artisan(['tinker', '--execute', createDatabase])

  artisan(['migrate:fresh', '--force'])
  // After migrate: the modules table is one of the things migrations create.
  artisan(['modules:sync'])
  artisan(['db:seed', '--class=DemoShopSeeder', '--force'])
}
