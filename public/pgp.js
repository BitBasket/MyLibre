(() => {
    const DB_NAME = 'mylibre-pgp';
    const STORE = 'keys';
    const KEY_ID = 'device';

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
            tx.objectStore(STORE).put({ publicArmored, privateArmored }, KEY_ID);
        });
        db.close();
    }

    async function loadKeys() {
        const db = await openDb();
        const value = await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const request = tx.objectStore(STORE).get(KEY_ID);
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
            tx.objectStore(STORE).delete(KEY_ID);
        });
        db.close();
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
        if (!result.signatures?.length) {
            throw new Error('Encrypted snapshot was not signed.');
        }
        try {
            await result.signatures[0].verified;
        } catch (error) {
            throw new Error('Encrypted snapshot signature is not valid for this keypair.');
        }

        return JSON.parse(result.data);
    }

    window.PgpVault = { saveKeys, loadKeys, clearKeys, unlock, decryptJson };
})();
