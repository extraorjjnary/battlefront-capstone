import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import test from 'node:test';
import { pathToFileURL } from 'node:url';
import { promisify } from 'node:util';
import { renderToString } from '@vue/server-renderer';
import { h } from 'vue';
import { loadSkeletonComponent } from '../lib/skeletonTestHelpers.js';

const executable = process.env.BATTLEFRONT_UI_BROWSER;

test(
    'built skeleton layouts fit mobile and desktop themes and stop animating for reduced motion',
    { skip: !executable },
    async (t) => {
        const fixtureDir = await mkdtemp(
            join(tmpdir(), 'battlefront-skeleton-'),
        );
        t.after(async () => {
            assert.equal(dirname(resolve(fixtureDir)), resolve(tmpdir()));
            assert.ok(
                fixtureDir.startsWith(join(tmpdir(), 'battlefront-skeleton-')),
            );
            await rm(fixtureDir, {
                recursive: true,
                force: true,
                maxRetries: 5,
                retryDelay: 200,
            });
        });
        const manifest = JSON.parse(
            await readFile('public/build/manifest.json', 'utf8'),
        );
        const css = pathToFileURL(
            resolve('public/build', manifest['resources/css/app.css'].file),
        ).href;
        const product = loadSkeletonComponent('ProductCardSkeleton.vue');
        const stats = loadSkeletonComponent('StatSkeleton.vue');
        const content = await renderToString(
            h('main', { class: 'flex flex-col gap-6' }, [
                h(
                    'div',
                    {
                        class: 'grid grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4',
                    },
                    Array.from({ length: 4 }, () => h(product)),
                ),
                h(
                    'div',
                    { class: 'grid sm:grid-cols-2 lg:grid-cols-4' },
                    Array.from({ length: 4 }, () => h(stats)),
                ),
                h('div', { class: 'overflow-x-auto' }, [
                    h('table', { class: 'w-full min-w-3xl' }, [
                        h(loadSkeletonComponent('TableRowsSkeleton.vue')),
                    ]),
                ]),
                h('div', { class: 'w-80 max-w-full' }, [
                    h(loadSkeletonComponent('ListSkeleton.vue')),
                ]),
                h(loadSkeletonComponent('RecommendationSkeleton.vue')),
            ]),
        );
        const fixture = join(fixtureDir, 'skeletons.html');
        await writeFile(
            fixture,
            `<!doctype html><html><head><meta charset="utf-8"></head><body>
        <pre id="result">Pending</pre><script>
        const content = ${JSON.stringify(content)};
        const css = ${JSON.stringify(css)};
        const frames = [];
        for (const width of [320, 375, 768, 1440]) {
            for (const theme of ['light', 'dark']) {
                const frame = document.createElement('iframe');
                frame.style.width = width + 'px';
                frame.style.height = '1800px';
                frame.dataset.width = width;
                frame.dataset.theme = theme;
                frames.push(new Promise((resolve) => {
                    frame.onload = () => resolve(frame);
                }));
                frame.srcdoc = '<!doctype html><html class="' + (theme === 'dark' ? 'dark' : '') + '"><head><link rel="stylesheet" href="' + css + '"></head><body class="bg-background p-6">' + content + '</body></html>';
                document.body.append(frame);
            }
        }
        Promise.all(frames).then((frames) => {
            const results = frames.map((frame) => {
                const doc = frame.contentDocument;
                const style = (element) => frame.contentWindow.getComputedStyle(element);
                const image = doc.querySelector('[data-slot="product-card-skeleton"] [data-slot="skeleton"]');
                return {
                    width: Number(frame.dataset.width), theme: frame.dataset.theme,
                    overflow: doc.documentElement.scrollWidth > doc.documentElement.clientWidth,
                    scrollWidth: doc.documentElement.scrollWidth, clientWidth: doc.documentElement.clientWidth,
                    imageRatio: image.getBoundingClientRect().width / image.getBoundingClientRect().height,
                    background: style(doc.body).backgroundColor,
                    reduced: frame.contentWindow.matchMedia('(prefers-reduced-motion: reduce)').matches,
                    animations: [...doc.querySelectorAll('[data-slot="skeleton"]')].map((element) => style(element).animationName),
                };
            });
            document.querySelector('#result').textContent = JSON.stringify(results);
        });
        </script></body></html>`,
        );

        for (const reduced of [false, true]) {
            const { stdout } = await promisify(execFile)(
                executable,
                [
                    '--headless',
                    '--disable-gpu',
                    '--no-first-run',
                    '--no-default-browser-check',
                    '--allow-file-access-from-files',
                    '--virtual-time-budget=3000',
                    '--dump-dom',
                    `--user-data-dir=${join(fixtureDir, reduced ? 'reduced' : 'normal')}`,
                    ...(reduced ? ['--force-prefers-reduced-motion'] : []),
                    pathToFileURL(fixture).href,
                ],
                { windowsHide: true, timeout: 30000, maxBuffer: 1024 * 1024 },
            );
            const results = JSON.parse(
                stdout.match(/<pre id="result">([^<]+)<\/pre>/)?.[1] ?? 'null',
            );
            assert.equal(
                results?.length,
                8,
                'Every viewport and theme must finish rendering',
            );
            for (const result of results) {
                assert.equal(
                    result.overflow,
                    false,
                    `${result.width}px ${result.theme} must fit: ${JSON.stringify(result)}`,
                );
                assert.ok(
                    Math.abs(
                        result.imageRatio - (result.width < 640 ? 1 : 1.5),
                    ) < 0.02,
                );
                assert.equal(
                    result.background,
                    result.theme === 'dark'
                        ? 'rgb(9, 11, 16)'
                        : 'rgb(255, 255, 255)',
                );
                if (reduced) {
                    assert.equal(result.reduced, true);
                    assert.ok(
                        result.animations.every((name) => name === 'none'),
                    );
                } else if (!result.reduced) {
                    assert.ok(
                        result.animations.every((name) => name === 'pulse'),
                    );
                }
            }
        }
    },
);
