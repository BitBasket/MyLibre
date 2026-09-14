(() => {
    const DB_NAME = 'mylibre-pgp';
    const STORE = 'keys';
    const keyId = 'device';

    function openDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE);
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function saveKeys(publicArmored, privateArmored) {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
            tx.objectStore(STORE).put({ publicArmored, privateArmored }, keyId);
        });
        db.close();
    }

    async function loadKeys() {
        const db = await openDb();
        const value = await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const request = tx.objectStore(STORE).get(keyId);
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
        db.close();
        return value;
    }

    async function clearKeys() {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
            tx.objectStore(STORE).delete(keyId);
        });
        db.close();
    }

    async function generateKeypair(passphrase) {
        if (!passphrase) {
            throw new Error('A passphrase is required.');
        }
        if (passphrase.length < 12) {
            throw new Error('Use a passphrase of at least 12 characters.');
        }

        // curve25519 -> Ed25519 signing key with a Curve25519 (ECDH) encryption
        // subkey, matching the server-side key generator.
        const { publicKey, privateKey } = await openpgp.generateKey({
            type: 'ecc',
            curve: 'curve25519',
            userIDs: [{ name: 'MyLibre', email: 'mylibre@example.com' }],
            passphrase,
            format: 'armored',
        });

        return { publicArmored: publicKey, privateArmored: privateKey };
    }

    async function unlock(publicArmored, privateArmored, passphrase) {
        if (!publicArmored.includes('BEGIN PGP PUBLIC KEY BLOCK')) {
            throw new Error('Public key must be an ASCII-armored OpenPGP public key.');
        }
        if (!privateArmored.includes('BEGIN PGP PRIVATE KEY BLOCK')) {
            throw new Error('Private key must be an ASCII-armored OpenPGP private key.');
        }
        if (!passphrase) {
            throw new Error('Passphrase is required.');
        }

        const publicKey = await openpgp.readKey({ armoredKey: publicArmored });
        let privateKey = await openpgp.readPrivateKey({ armoredKey: privateArmored });
        if (publicKey.getFingerprint().toUpperCase() !== privateKey.getFingerprint().toUpperCase()) {
            throw new Error('Public and private keys do not match.');
        }
        try {
            privateKey = await openpgp.decryptKey({ privateKey, passphrase });
        } catch (error) {
            throw new Error('Passphrase does not unlock this private key.');
        }

        return { publicKey, privateKey, publicArmored, privateArmored };
    }

    async function decryptJson(armored, keys) {
        if (!armored.includes('BEGIN PGP MESSAGE')) {
            throw new Error('Snapshot is not an encrypted PGP message.');
        }
        const message = await openpgp.readMessage({ armoredMessage: armored });
        const result = await openpgp.decrypt({
            message,
            decryptionKeys: keys.privateKey,
            verificationKeys: keys.publicKey,
        });
        if (result.signatures && result.signatures.length) {
            try {
                await result.signatures[0].verified;
            } catch (error) {
                throw new Error('Encrypted snapshot signature is not valid for this keypair.');
            }
        }
        // Unsigned snapshots (the server encrypted to this device's public key
        // without holding the private key) are still integrity-protected: the
        // decrypt() call above throws if the ciphertext was tampered with.

        return JSON.parse(result.data);
    }

    const PGP_MESSAGE_ARMOR = '-----BEGIN PGP MESSAGE-----';

    function decodeUtf8(bytes) {
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
        } catch (error) {
            return null;
        }
    }

    function looksLikeArmoredPgpMessage(text) {
        return typeof text === 'string' && text.includes(PGP_MESSAGE_ARMOR);
    }

    function looksLikeDenseCsv(text) {
        if (!text) {
            return false;
        }
        const lines = String(text).split(/\r?\n/);
        for (let i = 0; i < lines.length; i += 1) {
            const line = lines[i].trim();
            if (!line || line[0] === '#') {
                continue;
            }
            return /^\d{8}[,\t]/.test(line);
        }
        return false;
    }

    function looksLikePgpMessage(bytes, text) {
        if (looksLikeArmoredPgpMessage(text)) {
            return true;
        }
        if (looksLikeDenseCsv(text)) {
            return false;
        }
        return !!(bytes && bytes.length >= 2 && (bytes[0] & 0x80) !== 0);
    }

    async function readPgpMessage(bytes, text) {
        if (looksLikeArmoredPgpMessage(text)) {
            return openpgp.readMessage({ armoredMessage: text });
        }
        return openpgp.readMessage({ binaryMessage: bytes });
    }

    function pgpEncryptionKind(message) {
        const tags = openpgp.enums.packet;
        const packets = message.packets;
        const hasPublic = packets.filterByTag(tags.publicKeyEncryptedSessionKey).length > 0;
        const hasSymmetric = packets.filterByTag(tags.symEncryptedSessionKey).length > 0;
        if (hasPublic && hasSymmetric) {
            return 'both';
        }
        if (hasPublic) {
            return 'public';
        }
        if (hasSymmetric) {
            return 'symmetric';
        }
        return 'unknown';
    }

    async function decryptPgpPayload(message, options) {
        const result = await openpgp.decrypt({
            message,
            decryptionKeys: options && options.decryptionKeys,
            passwords: options && options.passwords,
        });
        return result.data;
    }

    window.PgpVault = {
        saveKeys,
        loadKeys,
        clearKeys,
        unlock,
        decryptJson,
        generateKeypair,
        decodeUtf8,
        looksLikePgpMessage,
        readPgpMessage,
        pgpEncryptionKind,
        decryptPgpPayload,
    };
})();
