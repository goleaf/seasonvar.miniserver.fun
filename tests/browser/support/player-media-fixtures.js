import { readFileSync } from 'node:fs';

const fixtureUrl = (filename) => new URL(`../fixtures/player/${filename}`, import.meta.url);
const readFixtureText = (filename) => readFileSync(fixtureUrl(filename), 'utf8');
const readFixtureBytes = (filename) => Buffer.from(readFixtureText(filename).trim(), 'base64');

const fixtures = new Map([
    ['/player-fixtures/direct.mp4', {
        body: readFixtureBytes('direct.mp4.b64'),
        contentType: 'video/mp4',
        range: true,
    }],
    ['/player-fixtures/hls-init.mp4', {
        body: readFixtureBytes('hls-init.mp4.b64'),
        contentType: 'video/mp4',
        range: true,
    }],
    ['/player-fixtures/hls-segment.m4s', {
        body: readFixtureBytes('hls-segment.m4s.b64'),
        contentType: 'video/mp4',
        range: true,
    }],
    ['/player-fixtures/valid.m3u8', {
        body: readFixtureText('valid.m3u8'),
        contentType: 'application/vnd.apple.mpegurl',
        range: false,
    }],
    ['/player-fixtures/subtitles-ru.vtt', {
        body: readFixtureText('subtitles-ru.vtt'),
        contentType: 'text/vtt; charset=utf-8',
        range: false,
    }],
]);

const configuredStatus = (path, scenario) => {
    if (path.endsWith('/valid.m3u8') && scenario.manifestStatuses.length > 0) {
        return scenario.manifestStatuses.shift();
    }

    if (path.endsWith('/hls-segment.m4s') && scenario.segmentStatuses.length > 0) {
        return scenario.segmentStatuses.shift();
    }

    if (path.endsWith('/subtitles-ru.vtt')) {
        return scenario.captionStatus;
    }

    return 200;
};

const rangeResponse = (range, body) => {
    const match = /^bytes=(\d+)-(\d*)$/.exec(range);

    if (!match) {
        return {
            status: 416,
            body: Buffer.alloc(0),
            headers: {
                'Accept-Ranges': 'bytes',
                'Content-Range': `bytes */${body.length}`,
            },
        };
    }

    const start = Number.parseInt(match[1], 10);
    const requestedEnd = match[2] === '' ? body.length - 1 : Number.parseInt(match[2], 10);

    if (start >= body.length || requestedEnd < start) {
        return {
            status: 416,
            body: Buffer.alloc(0),
            headers: {
                'Accept-Ranges': 'bytes',
                'Content-Range': `bytes */${body.length}`,
            },
        };
    }

    const end = Math.min(requestedEnd, body.length - 1);
    const partial = body.subarray(start, end + 1);

    return {
        status: 206,
        body: partial,
        headers: {
            'Accept-Ranges': 'bytes',
            'Content-Length': String(partial.length),
            'Content-Range': `bytes ${start}-${end}/${body.length}`,
        },
    };
};

export const installPlayerMediaFixtures = async (page) => {
    const scenario = {
        manifestStatuses: [],
        segmentStatuses: [],
        segmentBodies: [],
        captionStatus: 200,
    };
    const observations = [];

    await page.route('https://media.example.com/player-fixtures/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        const range = request.headers().range || null;
        const observation = { path, range, status: 0 };

        observations.push(observation);

        const fixture = fixtures.get(path);

        if (!fixture) {
            observation.status = 404;
            await route.fulfill({ status: 404, body: '' });

            return;
        }

        const status = configuredStatus(path, scenario);

        if (status !== 200) {
            observation.status = status;
            await route.fulfill({ status, body: '' });

            return;
        }

        let body = fixture.body;

        if (path.endsWith('/hls-segment.m4s') && scenario.segmentBodies.length > 0) {
            body = scenario.segmentBodies.shift() === 'corrupt'
                ? Buffer.from('invalid-fragment', 'utf8')
                : body;
        }

        if (fixture.range && range) {
            const response = rangeResponse(range, body);

            observation.status = response.status;
            await route.fulfill({
                ...response,
                contentType: fixture.contentType,
            });

            return;
        }

        observation.status = 200;
        await route.fulfill({
            status: 200,
            body,
            contentType: fixture.contentType,
            headers: fixture.range ? {
                'Accept-Ranges': 'bytes',
                'Content-Length': String(body.length),
            } : undefined,
        });
    });

    return {
        scenario,
        observations,
        count: (suffix) => observations.filter(({ path }) => path.endsWith(suffix)).length,
    };
};
