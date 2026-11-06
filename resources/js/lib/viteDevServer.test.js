import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer as createTcpServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from '@voidzero-dev/vite-plus-core';
import config from '../../../vite.config.js';

test('a duplicate Vite server fails instead of changing the development port', async (t) => {
    const reservation = createTcpServer();
    await new Promise((resolve, reject) => {
        reservation.once('error', reject);
        reservation.listen(0, '127.0.0.1', resolve);
    });
    const port = reservation.address().port;
    const cacheDir = await mkdtemp(join(tmpdir(), 'battlefront-vite-'));
    const server = await createServer({
        configFile: false,
        cacheDir,
        logLevel: 'silent',
        server: {
            ...config.server,
            host: '127.0.0.1',
            port,
            hmr: false,
            watch: null,
        },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    t.after(async () => {
        await server.close();
        await new Promise((resolve) => reservation.close(resolve));
        assert.ok(cacheDir.startsWith(join(tmpdir(), 'battlefront-vite-')));
        await rm(cacheDir, { recursive: true, force: true });
    });

    await assert.rejects(server.listen(), /Port \d+ is already in use/);
});

test('SSR imports stay responsive without watching generated runtime files', { timeout: 20000 }, async (t) => {
    const cacheDir = await mkdtemp(join(tmpdir(), 'battlefront-vite-ssr-'));
    const projectRoot = fileURLToPath(new URL('../../../', import.meta.url));
    const testingRoot = join(projectRoot, 'storage/framework/testing');
    await mkdir(testingRoot, { recursive: true });
    const runtimeDir = await mkdtemp(join(testingRoot, 'vite-watch-'));
    await writeFile(join(runtimeDir, 'fixture.txt'), 'Generated runtime fixture');
    let server;
    t.after(async () => {
        await server?.close();
        assert.ok(cacheDir.startsWith(join(tmpdir(), 'battlefront-vite-ssr-')));
        assert.ok(runtimeDir.startsWith(join(testingRoot, 'vite-watch-')));
        await rm(cacheDir, { recursive: true, force: true });
        await rm(runtimeDir, { recursive: true, force: true });
    });
    const pluginNames = [
        'laravel',
        'vite:vue',
        '@inertiajs/vite',
        '@laravel/vite-plugin-wayfinder',
        'vite-plugin-full-reload',
    ];
    const plugins = config.plugins.flat(Infinity)
        .filter((plugin) =>
            pluginNames.includes(plugin.name) || plugin.name.startsWith('@tailwindcss/'),
        )
        .map((plugin) => {
            // The probe must not own or delete the developer's public/hot file.
            return plugin.name === 'laravel'
                ? { ...plugin, configureServer() {} }
                : plugin;
        });
    server = await createServer({
        configFile: false,
        cacheDir,
        plugins,
        resolve: config.resolve,
        logLevel: 'silent',
        server: {
            ...config.server,
            middlewareMode: true,
            hmr: { host: '127.0.0.1', port: 0 },
        },
        optimizeDeps: { noDiscovery: true, include: [] },
    });

    let timer;
    try {
        await Promise.race([
            (async () => {
                const module = await server.ssrLoadModule('/resources/js/composables/useAppearance.js');
                assert.equal(typeof module.useAppearance, 'function');
                const normalize = (path) => path.replaceAll('\\', '/');
                assert.equal(
                    Object.keys(server.watcher.getWatched()).some((path) =>
                        normalize(path).startsWith(normalize(runtimeDir)),
                    ),
                    false,
                    'Generated test storage must not enter the source watcher',
                );
            })(),
            new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error('SSR appearance import did not finish')), 10000);
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
});
