export const browserSummary = () => {
    const agent = navigator.userAgent;
    const families = [
        ['edge', /Edg\/(\d+)/],
        ['opera', /OPR\/(\d+)/],
        ['samsung', /SamsungBrowser\/(\d+)/],
        ['firefox', /Firefox\/(\d+)/],
        ['chromium', /(?:Chrome|CriOS)\/(\d+)/],
        ['safari', /Version\/(\d+).+Safari/],
    ];

    for (const [family, pattern] of families) {
        const match = agent.match(pattern);

        if (match) {
            return { family, major: Number.parseInt(match[1], 10) || null };
        }
    }

    return { family: 'unknown', major: null };
};

export const operatingSystem = () => {
    const agent = navigator.userAgent;

    if (/Android/i.test(agent)) return 'android';
    if (/iPhone|iPad|iPod/i.test(agent)) return 'ios';
    if (/Windows/i.test(agent)) return 'windows';
    if (/CrOS/i.test(agent)) return 'chromeos';
    if (/Macintosh|Mac OS X/i.test(agent)) return 'macos';
    if (/Linux/i.test(agent)) return 'linux';

    return 'unknown';
};

export const deviceCategory = () => {
    const width = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    const agent = navigator.userAgent;

    if (/SmartTV|SMART-TV|HbbTV|NetCast|Tizen/i.test(agent)) return 'television';
    if (/iPad|Tablet/i.test(agent) || (/Android/i.test(agent) && !/Mobile/i.test(agent))) return 'tablet';
    if (/Mobile|iPhone|iPod|Android/i.test(agent) || width < 640) return 'mobile';

    return 'desktop';
};
