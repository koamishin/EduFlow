const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

async function smoothScroll(page, distance, durationMs = 1500) {
  const steps = 30;
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
    cursor.style.width = '22px';
    cursor.style.height = '22px';
    cursor.style.borderRadius = '50%';
    cursor.style.backgroundColor = 'rgba(39, 117, 202, 0.75)';
    cursor.style.border = '2px solid #ffffff';
    cursor.style.boxShadow = '0 0 10px rgba(39, 117, 202, 0.8), 0 2px 4px rgba(0,0,0,0.3)';
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
      cursor.style.transform = 'translate(-50%, -50%) scale(0.8)';
      cursor.style.backgroundColor = 'rgba(16, 185, 129, 0.9)';
    });

    window.addEventListener('mouseup', () => {
      cursor.style.transform = 'translate(-50%, -50%) scale(1)';
      cursor.style.backgroundColor = 'rgba(39, 117, 202, 0.75)';
    });
  });
}

async function moveMouseSmooth(page, targetX, targetY, steps = 20) {
  await page.mouse.move(targetX, targetY, { steps });
}

(async () => {
  const rawDir = path.resolve(__dirname, '../demo/raw');
  if (!fs.existsSync(rawDir)) {
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

  console.log('[Beat 1] Landing Page & Architecture');
  await page.goto('http://127.0.0.1:8000/', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await moveMouseSmooth(page, 960, 500, 15);
  await page.waitForTimeout(2000);
  await smoothScroll(page, 600, 2000);
  await page.waitForTimeout(1500);
  await smoothScroll(page, -600, 1500);
  await page.waitForTimeout(1000);

  console.log('[Beat 2] Login to Institutional Finance Console');
  await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await moveMouseSmooth(page, 960, 420, 15);
  await page.fill('input[name="email"]', 'finance@opensinop.com');
  await page.waitForTimeout(400);
  await moveMouseSmooth(page, 960, 480, 10);
  await page.fill('input[name="password"]', 'password');
  await page.waitForTimeout(400);
  await moveMouseSmooth(page, 960, 560, 10);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2500);

  console.log('[Beat 3] Policy Studio & JEV Simulator');
  await page.goto('http://127.0.0.1:8000/finance/policies', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1500);
  await moveMouseSmooth(page, 800, 380, 20);
  await smoothScroll(page, 450, 2000);
  await page.waitForTimeout(2000);
  await smoothScroll(page, -450, 1500);
  await page.waitForTimeout(1000);

  console.log('[Beat 4] JEV Decision Telemetry & Analytics');
  await page.goto('http://127.0.0.1:8000/finance/jev-analytics', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1500);
  await moveMouseSmooth(page, 960, 400, 20);
  await smoothScroll(page, 500, 2500);
  await page.waitForTimeout(2000);
  await smoothScroll(page, -500, 1500);
  await page.waitForTimeout(1000);

  console.log('[Beat 5] Student Web3 Tuition Payment on Arc');
  // Log out or switch to student session
  await page.goto('http://127.0.0.1:8000/student/pay', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1500);
  await moveMouseSmooth(page, 960, 450, 20);
  await smoothScroll(page, 400, 2000);
  await page.waitForTimeout(2000);

  console.log('[Beat 6] Student Bills & On-Chain Audit E-Receipts');
  await page.goto('http://127.0.0.1:8000/student/bills', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1500);
  await moveMouseSmooth(page, 960, 450, 20);
  await smoothScroll(page, 350, 2000);
  await page.waitForTimeout(2500);

  console.log('Finalizing recording...');
  await page.close();
  await context.close();
  await browser.close();

  console.log('Recording saved successfully in demo/raw/');
})();
