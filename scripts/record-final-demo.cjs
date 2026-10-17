const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

async function smoothScroll(page, distance, durationMs = 1800) {
  const steps = 25;
  const stepDistance = distance / steps;
  const stepTime = durationMs / steps;
  for (let i = 0; i < steps; i++) {
    await page.mouse.wheel(0, stepDistance);
    await page.waitForTimeout(stepTime);
  }
}

async function injectCursor(page) {
  await page.evaluate(() => {
    if (document.getElementById('demo-cursor')) return;
    const cursor = document.createElement('div');
    cursor.id = 'demo-cursor';
    cursor.style.position = 'fixed';
    cursor.style.top = '0';
    cursor.style.left = '0';
    cursor.style.width = '24px';
    cursor.style.height = '24px';
    cursor.style.borderRadius = '50%';
    cursor.style.backgroundColor = 'rgba(39, 117, 202, 0.75)';
    cursor.style.border = '2px solid #ffffff';
    cursor.style.boxShadow = '0 0 12px rgba(39, 117, 202, 0.8), 0 2px 4px rgba(0,0,0,0.3)';
    cursor.style.pointerEvents = 'none';
    cursor.style.zIndex = '999999';
    cursor.style.transition = 'transform 0.08s ease-out, background-color 0.15s ease';
    cursor.style.transform = 'translate(-50%, -50%)';
    document.body.appendChild(cursor);

    window.addEventListener('mousemove', (e) => {
      cursor.style.left = `${e.clientX}px`;
      cursor.style.top = `${e.clientY}px`;
    });

    window.addEventListener('mousedown', () => {
      cursor.style.transform = 'translate(-50%, -50%) scale(0.85)';
      cursor.style.backgroundColor = 'rgba(16, 185, 129, 0.9)';
    });

    window.addEventListener('mouseup', () => {
      cursor.style.transform = 'translate(-50%, -50%) scale(1)';
      cursor.style.backgroundColor = 'rgba(39, 117, 202, 0.75)';
    });
  });
}

async function moveMouseSmooth(page, targetX, targetY, steps = 15) {
  await page.mouse.move(targetX, targetY, { steps });
}

(async () => {
  const rawDir = path.resolve(__dirname, '../demo/raw');
  // Clean raw directory
  if (fs.existsSync(rawDir)) {
    const files = fs.readdirSync(rawDir);
    for (const f of files) {
      if (f.endsWith('.webm')) fs.unlinkSync(path.join(rawDir, f));
    }
  } else {
    fs.mkdirSync(rawDir, { recursive: true });
  }

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1920,1080'],
  });

  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    recordVideo: {
      dir: rawDir,
      size: { width: 1920, height: 1080 },
    },
  });

  const page = await context.newPage();

  console.log('[Beat 1] Landing Page & Vision');
  await page.goto('http://127.0.0.1:8000/', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await moveMouseSmooth(page, 960, 500);
  await page.waitForTimeout(2000);
  await smoothScroll(page, 550, 2000);
  await page.waitForTimeout(1500);
  await smoothScroll(page, -550, 1500);
  await page.waitForTimeout(1000);

  console.log('[Beat 2] Login as Finance Officer');
  await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await moveMouseSmooth(page, 960, 420);
  await page.fill('input[name="email"]', 'finance@opensinop.com');
  await page.waitForTimeout(300);
  await moveMouseSmooth(page, 960, 480);
  await page.fill('input[name="password"]', 'password');
  await page.waitForTimeout(300);
  await moveMouseSmooth(page, 960, 560);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2500);

  console.log('[Beat 3] Institution Policy Studio & Templates');
  await page.goto('http://127.0.0.1:8000/finance/policies', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1800);
  await moveMouseSmooth(page, 800, 450);
  await smoothScroll(page, 480, 2200);
  await page.waitForTimeout(2000);
  await smoothScroll(page, -480, 1600);
  await page.waitForTimeout(1200);

  console.log('[Beat 4] JEV Decision Telemetry & Analytics Dashboard');
  await page.goto('http://127.0.0.1:8000/finance/jev-analytics', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1800);
  await moveMouseSmooth(page, 960, 420);
  await smoothScroll(page, 500, 2500);
  await page.waitForTimeout(2000);
  await smoothScroll(page, -500, 1600);
  await page.waitForTimeout(1200);

  console.log('[Beat 5] Log out and switch to Student Web3 Portal');
  await context.clearCookies();
  await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await moveMouseSmooth(page, 960, 420);
  await page.fill('input[name="email"]', 'juan@opensinop.com');
  await page.waitForTimeout(300);
  await moveMouseSmooth(page, 960, 480);
  await page.fill('input[name="password"]', 'password');
  await page.waitForTimeout(300);
  await moveMouseSmooth(page, 960, 560);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2500);

  console.log('[Beat 6] Student Tuition Payment on Arc');
  await page.goto('http://127.0.0.1:8000/student/pay', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2000);
  await moveMouseSmooth(page, 960, 450);
  await smoothScroll(page, 380, 2000);
  await page.waitForTimeout(2000);
  await smoothScroll(page, -380, 1500);
  await page.waitForTimeout(1000);

  console.log('[Beat 7] Student Bills & On-Chain Audit Records');
  await page.goto('http://127.0.0.1:8000/student/bills', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2000);
  await moveMouseSmooth(page, 960, 400);
  await smoothScroll(page, 250, 1800);
  await page.waitForTimeout(2500);

  console.log('Finalizing recording...');
  await page.close();
  await context.close();
  await browser.close();

  console.log('Recording completed successfully!');
})();
