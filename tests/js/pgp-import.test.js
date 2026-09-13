'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');
const { webcrypto } = require('crypto');
const { ReadableStream, WritableStream, TransformStream } = require('stream/web');

const root = path.resolve(__dirname, '../..');
const CSV = '20260913,185,,184,186\n';

function fail(message) {
    console.error(message);
    process.exit(1);
}

function assert(cond, message) {
    if (!cond) {
        fail(message);
    }
}

function loadPgpVault() {
    const sandbox = {
        console,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        Uint8Array,
        ArrayBuffer,
        TextEncoder,
        TextDecoder,
        atob: (data) => Buffer.from(data, 'base64').toString('binary'),
        btoa: (data) => Buffer.from(data, 'binary').toString('base64'),
        Buffer,
        crypto: webcrypto,
        ReadableStream,
        WritableStream,
        TransformStream,
        indexedDB: {
            open() {
                throw new Error('indexedDB is not used in this test');
            },
        },
    };
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    sandbox.global = sandbox;
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(path.join(root, 'public/vendor/openpgp.min.js'), 'utf8'), sandbox);
    vm.runInContext(fs.readFileSync(path.join(root, 'public/pgp.js'), 'utf8'), sandbox);
    return { openpgp: sandbox.openpgp, PgpVault: sandbox.PgpVault };
}

function gpg(home, args, input) {
    const result = spawnSync('gpg', ['--homedir', home, '--batch', '--yes', '--no-tty', '--disable-dirmngr', ...args], {
        input: input || '',
        encoding: null,
        maxBuffer: 10 * 1024 * 1024,
    });
    if (result.status !== 0) {
        fail(`gpg ${args.join(' ')} failed: ${result.stderr.toString()}`);
    }
    return result;
}

async function main() {
    const { openpgp, PgpVault } = loadPgpVault();
    const home = fs.mkdtempSync(path.join(os.tmpdir(), 'mylibre-pgp-import-'));
    fs.chmodSync(home, 0o700);

    try {
        const csvBytes = new TextEncoder().encode(CSV);
        assert(!PgpVault.looksLikePgpMessage(csvBytes, CSV), 'plaintext CSV must not look like PGP');

        gpg(home, [
            '--pinentry-mode', 'loopback',
            '--passphrase', 'csv-secret',
            '--symmetric',
            '--armor',
            '--cipher-algo', 'AES256',
            '--output', path.join(home, 'sym.asc'),
        ], CSV);
        const symArmor = fs.readFileSync(path.join(home, 'sym.asc'), 'utf8');
        const symArmorBytes = new TextEncoder().encode(symArmor);
        assert(PgpVault.looksLikePgpMessage(symArmorBytes, symArmor), 'armored symmetric must look like PGP');
        let message = await PgpVault.readPgpMessage(symArmorBytes, symArmor);
        assert(PgpVault.pgpEncryptionKind(message) === 'symmetric', 'expected symmetric SKESK');
        const fromArmor = await PgpVault.decryptPgpPayload(message, { passwords: ['csv-secret'] });
        assert(fromArmor === CSV, `symmetric armor decrypt mismatch: ${JSON.stringify(fromArmor)}`);

        gpg(home, [
            '--pinentry-mode', 'loopback',
            '--passphrase', 'csv-secret',
            '--symmetric',
            '--cipher-algo', 'AES256',
            '--output', path.join(home, 'sym.gpg'),
        ], CSV);
        const symBin = new Uint8Array(fs.readFileSync(path.join(home, 'sym.gpg')));
        const symBinText = PgpVault.decodeUtf8(symBin);
        assert(PgpVault.looksLikePgpMessage(symBin, symBinText), 'binary symmetric must look like PGP');
        message = await PgpVault.readPgpMessage(symBin, symBinText);
        assert(PgpVault.pgpEncryptionKind(message) === 'symmetric', 'expected binary symmetric SKESK');
        const fromBin = await PgpVault.decryptPgpPayload(message, { passwords: ['csv-secret'] });
        assert(fromBin === CSV, `symmetric binary decrypt mismatch: ${JSON.stringify(fromBin)}`);

        gpg(home, [
            '--pinentry-mode', 'loopback',
            '--passphrase', 'key-passphrase-12',
            '--quick-generate-key',
            'MyLibre Test <mylibre@example.com>',
            'default',
            'default',
            'never',
        ]);
        const publicArmored = gpg(home, ['--export', '--armor']).stdout.toString();
        const privateArmored = gpg(home, [
            '--pinentry-mode', 'loopback',
            '--passphrase', 'key-passphrase-12',
            '--export-secret-keys',
            '--armor',
        ]).stdout.toString();
        const fpr = gpg(home, ['--with-colons', '--list-keys']).stdout.toString().split('\n')
            .find((line) => line.startsWith('fpr:'))
            .split(':')[9];
        gpg(home, ['--encrypt', '--armor', '--trust-model', 'always', '--recipient', fpr, '--output', path.join(home, 'pub.asc')], CSV);

        const pubArmor = fs.readFileSync(path.join(home, 'pub.asc'), 'utf8');
        const pubBytes = new TextEncoder().encode(pubArmor);
        message = await PgpVault.readPgpMessage(pubBytes, pubArmor);
        assert(PgpVault.pgpEncryptionKind(message) === 'public', 'expected public-key PKESK');

        const unlocked = await PgpVault.unlock(publicArmored, privateArmored, 'key-passphrase-12');
        const fromPub = await PgpVault.decryptPgpPayload(message, { decryptionKeys: unlocked.privateKey });
        assert(fromPub === CSV, `public-key decrypt mismatch: ${JSON.stringify(fromPub)}`);

        try {
            await PgpVault.decryptPgpPayload(await PgpVault.readPgpMessage(pubBytes, pubArmor), {
                passwords: ['csv-secret'],
            });
            fail('public-key message must not decrypt with a symmetric password');
        } catch (error) {
            assert(true, 'public-key reject-password');
        }

        try {
            await PgpVault.decryptPgpPayload(await PgpVault.readPgpMessage(symArmorBytes, symArmor), {
                decryptionKeys: unlocked.privateKey,
            });
            fail('symmetric message must not decrypt with the private key');
        } catch (error) {
            assert(true, 'symmetric reject-key');
        }

        // openpgp is loaded; keep a reference so the sandbox is not GC'd mid-await
        assert(typeof openpgp.decrypt === 'function', 'openpgp.decrypt missing');
        console.log('pgp-import.test.js: ok');
    } finally {
        fs.rmSync(home, { recursive: true, force: true });
    }
}

main().catch((error) => {
    fail(error.stack || String(error));
});
