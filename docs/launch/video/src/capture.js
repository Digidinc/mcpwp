// Records each scene HTML slide as a video clip using Playwright + Chromium.
// Run inside the mcr.microsoft.com/playwright docker image (see capture.sh).
//
// Each scene is a self-contained HTML page (1920x1080) with CSS animations
// timed to the scene's total duration. We open it, let the animation play
// for that duration, and Playwright's built-in video recorder captures the
// real rendered frames (this is how the JSON-RPC "code panel" slides and the
// full-bleed gallery-screenshot slides both get "recorded the same way").

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const SRC = '/work/src/slides';
const OUT = '/work/out';

const scenes = [
  { file: 'scene1.html', ms: 7000, name: 'scene1' },
  { file: 'scene2.html', ms: 9000, name: 'scene2' },
  { file: 'scene3.html', ms: 15000, name: 'scene3' },
  { file: 'scene4.html', ms: 11000, name: 'scene4' },
  { file: 'scene5.html', ms: 11000, name: 'scene5' },
  { file: 'scene6.html', ms: 9000, name: 'scene6' },
  { file: 'scene7.html', ms: 7000, name: 'scene7' },
];

(async () => {
  const browser = await chromium.launch();
  for (const scene of scenes) {
    const tmpDir = path.join(OUT, `_raw_${scene.name}`);
    fs.mkdirSync(tmpDir, { recursive: true });
    const context = await browser.newContext({
      viewport: { width: 1920, height: 1080 },
      deviceScaleFactor: 1,
      recordVideo: { dir: tmpDir, size: { width: 1920, height: 1080 } },
    });
    const page = await context.newPage();
    const filePath = 'file://' + path.join(SRC, scene.file);
    await page.goto(filePath);
    await page.waitForTimeout(scene.ms + 400); // small pad so ffmpeg trim never runs short
    const video = page.video();
    await page.close();
    await context.close();
    const savedPath = await video.path();
    const dest = path.join(OUT, `${scene.name}.webm`);
    fs.renameSync(savedPath, dest);
    fs.rmSync(tmpDir, { recursive: true, force: true });
    console.log(`captured ${scene.name} -> ${dest}`);
  }
  await browser.close();
})();
