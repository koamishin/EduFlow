const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

async function smoothScroll(page, distance, durationMs = 2500) {
  const steps = 35;
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

async function moveMouseSmooth(page, targetX, targetY, steps = 25) {
  await page.mouse.move(targetX, targetY, { steps });
}

(async () => {
  const rawDir = path.resolve(__dirname, '../demo/raw_extended');
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

  // ==========================================
  // SCENE 1: Introduction & Architecture (0:00 - ~0:22)
  // ==========================================
  console.log('[Scene 1] OpenSinop Platform Landing & Architecture Overview');
  await page.goto('http://127.0.0.1:8000/', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await moveMouseSmooth(page, 960, 500);
  await page.waitForTimeout(3000);
  await moveMouseSmooth(page, 500, 485);
  await page.waitForTimeout(1500);
  await moveMouseSmooth(page, 960, 680);
  await smoothScroll(page, 550, 2500);
  await page.waitForTimeout(3500);
  await moveMouseSmooth(page, 620, 720);
  await page.waitForTimeout(2000);
  await smoothScroll(page, -550, 2000);
  await page.waitForTimeout(2000);

  // ==========================================
  // SCENE 2: Multi-School Onboarding & Arc Rails (~0:22 - ~0:44)
  // ==========================================
  console.log('[Scene 2] Deploy School Institution & Arc Treasury Binding');
  await page.goto('http://127.0.0.1:8000/institutions/register', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 500, 360);
  await page.waitForTimeout(1500);
  await smoothScroll(page, 450, 2500);
  await page.waitForTimeout(2000);
  await moveMouseSmooth(page, 380, 750);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 610, 750);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 700, 850);
  await page.waitForTimeout(3000);

  // ==========================================
  // SCENE 3: Role-Based Authentication (~0:44 - ~0:58)
  // ==========================================
  console.log('[Scene 3] Institutional Officer Login');
  await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2000);
  await moveMouseSmooth(page, 960, 420);
  await page.fill('input[name="email"]', 'finance@opensinop.com');
  await page.waitForTimeout(600);
  await moveMouseSmooth(page, 960, 480);
  await page.fill('input[name="password"]', 'password');
  await page.waitForTimeout(600);
  await moveMouseSmooth(page, 960, 560);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3500);

  // ==========================================
  // SCENE 4: Institution Policy Studio & Playbooks (~0:58 - ~1:26)
  // ==========================================
  console.log('[Scene 4] Institution Policy Studio & 1-Click Playbooks');
  await page.goto('http://127.0.0.1:8000/finance/policies', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(3000);
  await moveMouseSmooth(page, 310, 480);
  await page.waitForTimeout(2500);
  await smoothScroll(page, 520, 3000);
  await page.waitForTimeout(3500);
  await moveMouseSmooth(page, 490, 450);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 670, 450);
  await page.waitForTimeout(2500);
  await smoothScroll(page, -520, 2000);
  await page.waitForTimeout(2000);

  // ==========================================
  // SCENE 5: Interactive JEV AI Decision Simulator (~1:26 - ~1:58)
  // ==========================================
  console.log('[Scene 5] Interactive JEV AI Decision Simulator');
  await moveMouseSmooth(page, 325, 420);
  await page.click('text="Test a Sample Bill"');
  await page.waitForTimeout(2500);
  await smoothScroll(page, 300, 1500);
  await page.waitForTimeout(2000);
  await moveMouseSmooth(page, 735, 780);
  await page.click('text="Check This Bill"');
  await page.waitForTimeout(3500);
  await smoothScroll(page, 380, 2000);
  await page.waitForTimeout(4000);
  await moveMouseSmooth(page, 960, 720);
  await page.waitForTimeout(4000);
  await smoothScroll(page, -400, 1500);
  await page.waitForTimeout(1500);

  // ==========================================
  // SCENE 6: Executive Telemetry & Governance (~1:58 - ~2:28)
  // ==========================================
  console.log('[Scene 6] Executive JEV Telemetry & Human Governance Analytics');
  await page.goto('http://127.0.0.1:8000/finance/jev-analytics', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(3000);
  await moveMouseSmooth(page, 480, 300);
  await page.waitForTimeout(2500);
  await smoothScroll(page, 520, 3000);
  await page.waitForTimeout(4000);
  await moveMouseSmooth(page, 300, 650);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 600, 650);
  await page.waitForTimeout(3000);
  await smoothScroll(page, 350, 2000);
  await page.waitForTimeout(3500);
  await smoothScroll(page, -870, 2500);
  await page.waitForTimeout(2000);

  // ==========================================
  // SCENE 7: Student Web3 Portal Login (~2:28 - ~2:50)
  // ==========================================
  console.log('[Scene 7] Student Web3 Portal Login & Dashboard');
  await context.clearCookies();
  await page.goto('http://127.0.0.1:8000/login', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(1500);
  await moveMouseSmooth(page, 960, 420);
  await page.fill('input[name="email"]', 'juan@opensinop.com');
  await page.waitForTimeout(500);
  await moveMouseSmooth(page, 960, 480);
  await page.fill('input[name="password"]', 'password');
  await page.waitForTimeout(500);
  await moveMouseSmooth(page, 960, 560);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3000);

  await page.goto('http://127.0.0.1:8000/student/dashboard', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 330, 340);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 760, 340);
  await page.waitForTimeout(2500);
  await smoothScroll(page, 350, 2000);
  await page.waitForTimeout(3000);

  // ==========================================
  // SCENE 8: Direct USDC Tuition Settlement on Arc (~2:50 - ~3:22)
  // ==========================================
  console.log('[Scene 8] Direct USDC Tuition Settlement on Arc');
  await page.goto('http://127.0.0.1:8000/student/pay', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 320, 340);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 498, 422); // MAX / Full chip
  await page.waitForTimeout(2000);
  await smoothScroll(page, 380, 2000);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 420, 580); // Route details
  await page.waitForTimeout(3000);
  await moveMouseSmooth(page, 530, 800); // Gas guarantee
  await page.waitForTimeout(3000);
  await smoothScroll(page, -380, 1500);
  await page.waitForTimeout(1500);

  // ==========================================
  // SCENE 9: Campus Bills & Capstone Fees (~3:22 - ~3:44)
  // ==========================================
  console.log('[Scene 9] Student Campus Bills & Capstone Fees');
  await page.goto('http://127.0.0.1:8000/student/bills', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 200, 210);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 185, 305); // Pay this bill button
  await page.waitForTimeout(3000);
  await smoothScroll(page, 250, 1500);
  await page.waitForTimeout(3000);

  // ==========================================
  // SCENE 10: Bounded Autonomy Financial Assistance (~3:44 - ~4:04)
  // ==========================================
  console.log('[Scene 10] Bounded Autonomy Financial Assistance Requests');
  await page.goto('http://127.0.0.1:8000/financial-assistance', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 420, 310);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 560, 310);
  await page.waitForTimeout(2500);
  await smoothScroll(page, 320, 1800);
  await page.waitForTimeout(3000);

  // ==========================================
  // SCENE 11: Official E-Receipts & On-Chain Audit (~4:04 - ~4:25)
  // ==========================================
  console.log('[Scene 11] Official Payment E-Receipts & On-Chain Audit Proof');
  await page.goto('http://127.0.0.1:8000/payments', { waitUntil: 'networkidle' });
  await injectCursor(page);
  await page.waitForTimeout(2500);
  await moveMouseSmooth(page, 450, 230);
  await page.waitForTimeout(2500);
  await smoothScroll(page, 300, 1800);
  await page.waitForTimeout(3500);
  await smoothScroll(page, -300, 1500);
  await page.waitForTimeout(2500);

  console.log('Finalizing extended recording...');
  await page.close();
  await context.close();
  await browser.close();

  console.log('Extended recording finished successfully!');
})();
