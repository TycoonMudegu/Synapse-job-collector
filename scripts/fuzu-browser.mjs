import { createInterface } from "node:readline";
import { isIP } from "node:net";
import { pathToFileURL } from "node:url";

const origin = "https://www.fuzu.com";
const assets = "https://public-s3.fuzu.com";
const publicPath =
  /^\/(?:robots\.txt|kenya\/job(?:\/computers-software-development)?|kenya\/jobs\/[a-z0-9-]+)$/;

export function publicPage(value) {
  try {
    const url = new URL(value);
    return (
      url.origin === origin &&
      !url.username &&
      !url.password &&
      !url.search &&
      !url.hash &&
      publicPath.test(url.pathname)
    );
  } catch {
    return false;
  }
}

function robotsAllow(robots, path) {
  let agents = [],
    inRules = false,
    best = -1,
    allowed = true;
  for (const raw of robots.split(/\r?\n/)) {
    const line = raw.split("#")[0].trim();
    const colon = line.indexOf(":");
    if (colon < 0) continue;
    const directive = line.slice(0, colon).trim().toLowerCase();
    const value = line.slice(colon + 1).trim();
    if (directive === "user-agent") {
      if (inRules) agents = [];
      inRules = false;
      agents.push(value.toLowerCase());
      continue;
    }
    inRules = true;
    if (
      !agents.some((agent) => ["*", "jobflow"].includes(agent)) ||
      !["allow", "disallow"].includes(directive) ||
      !value
    )
      continue;
    const pattern = value
      .replace(/[.+?^${}()|[\]\\]/g, "\\$&")
      .replace(/\*/g, ".*")
      .replace(/\\\$$/, "$");
    if (new RegExp(`^${pattern}`).test(path) && value.length >= best) {
      best = value.length;
      allowed = directive === "allow";
    }
  }
  return allowed;
}

export async function browserSession(browser) {
  const context = await browser.newContext({
    serviceWorkers: "block",
    acceptDownloads: false,
  });
  const page = await context.newPage();
  let robots = null;
  let redirected = false;
  // Playwright routing sees only the first request of an HTTP redirect chain.
  // Stop redirect responses in Chromium before it can contact the next address.
  const protocol = await context.newCDPSession(page);
  await protocol.send("Fetch.enable", {
    patterns: [{ urlPattern: "*", requestStage: "Response" }],
  });
  protocol.on("Fetch.requestPaused", ({ requestId, responseStatusCode }) => {
    if (responseStatusCode >= 300 && responseStatusCode < 400) {
      redirected = true;
      void protocol
        .send("Fetch.failRequest", {
          requestId,
          errorReason: "BlockedByClient",
        })
        .catch(() => {});
    } else {
      void protocol
        .send("Fetch.continueResponse", { requestId })
        .catch(() => {});
    }
  });
  await context.route("**/*", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const navigation = request.isNavigationRequest();
    if (navigation && request.redirectedFrom()) redirected = true;
    const permitted =
      request.method() === "GET" &&
      !url.username &&
      !url.password &&
      [origin, assets].includes(url.origin) &&
      !request.redirectedFrom() &&
      (!navigation ||
        (request.frame() === page.mainFrame() && publicPage(url.href))) &&
      (robots === null
        ? url.href === `${origin}/robots.txt`
        : robotsAllow(robots, url.pathname + url.search));
    if (!permitted) return route.abort("blockedbyclient");
    await route.fallback();
  });
  await context.routeWebSocket("**/*", (socket) => socket.close());

  return {
    async read(url, { timeout = 45, maxBytes = 4194304 } = {}) {
      if (!publicPage(url))
        throw new Error(
          "The browser collector only opens built-in public Fuzu pages.",
        );
      if (robots === null && url !== `${origin}/robots.txt`)
        throw new Error(
          "Fuzu robots permissions must be checked before browsing.",
        );
      redirected = false;
      let response;
      // Chromium can reject navigation after receiving an empty 4xx page.
      // Preserve that main-frame response so robots 404 and access denial
      // remain HTTP results rather than indistinguishable network failures.
      const capture = (candidate) => {
        if (
          candidate.url() === url &&
          candidate.request().isNavigationRequest() &&
          candidate.frame() === page.mainFrame()
        )
          response = candidate;
      };
      page.on("response", capture);
      try {
        response = await page.goto(url, {
          waitUntil: "domcontentloaded",
          timeout: timeout * 1000,
        });
      } catch {
        if (redirected || !response || response.status() < 400)
          throw new Error(
            redirected
              ? "Fuzu redirected the browser. Redirects and login pages are not followed."
              : "Fuzu browser navigation failed or timed out. Check site access and the browser installation.",
          );
      } finally {
        page.off("response", capture);
      }
      if (
        !response ||
        response.url() !== url ||
        (response.ok() && page.url() !== url)
      )
        throw new Error("Fuzu did not return the requested public page.");
      const status = response.status();
      if (url === `${origin}/robots.txt`) {
        const body = status === 200 ? await response.text() : "";
        if (Buffer.byteLength(body) > maxBytes)
          throw new Error("The Fuzu robots file exceeds the page size limit.");
        if ([200, 404, 410].includes(status)) robots = body;
        return { status, body };
      }
      // A denied response stops immediately; do not retry or interact with a challenge.
      if (status < 200 || status >= 300) return { status, body: "" };
      const challenge = await page.title();
      if (
        /just a moment|attention required|verify (?:you are|that you are) human|captcha/i.test(
          challenge ?? "",
        )
      ) {
        throw new Error(
          "Fuzu requires a browser security check. Open Fuzu to browse or use optional email alerts. No jobs were collected.",
        );
      }
      try {
        await page.waitForFunction(
          (detail) =>
            detail
              ? [
                  ...document.querySelectorAll(
                    'script[type="application/ld+json"]',
                  ),
                ].some((node) => /"JobPosting"/.test(node.textContent))
              : !!document.querySelector('a[href*="/kenya/jobs/"]'),
          url.includes("/kenya/jobs/"),
          { timeout: Math.min(timeout * 1000, 10000) },
        );
      } catch {
        throw new Error(
          "Fuzu did not render public vacancy data. The page may require a security check or its layout has changed.",
        );
      }
      const body = await page.content();
      if (Buffer.byteLength(body) > maxBytes)
        throw new Error("The Fuzu page exceeds the collection size limit.");
      return { status, body };
    },
    close: () => context.close(),
  };
}

async function main() {
  let browser;
  let session;
  const input = createInterface({ input: process.stdin, crlfDelay: Infinity });
  // Bound abandoned runs and close Chromium if PHP terminates the child.
  const lifetime = setTimeout(
    () => process.kill(process.pid, "SIGTERM"),
    10 * 60 * 1000,
  );
  process.once("SIGTERM", async () => {
    setTimeout(() => process.exit(1), 1500);
    await browser?.close().catch(() => {});
    process.exit(1);
  });
  try {
    for await (const line of input) {
      const request = JSON.parse(line);
      if (!browser) {
        const hosts = ["www.fuzu.com", "public-s3.fuzu.com"];
        if (!hosts.every((host) => isIP(request.hosts?.[host])))
          throw new Error("Fuzu public host addresses were not supplied.");
        const { chromium } = await import("playwright");
        browser = await chromium.launch({
          headless: process.env.FUZU_BROWSER_HEADLESS === "true",
          executablePath: process.env.CHROMIUM_PATH || undefined,
          timeout: 15000,
          args: [
            "--no-sandbox",
            "--disable-dev-shm-usage",
            "--no-proxy-server",
            `--host-resolver-rules=${hosts.map((host) => `MAP ${host} ${isIP(request.hosts[host]) === 6 ? `[${request.hosts[host]}]` : request.hosts[host]}`).join(", ")}, MAP * ~NOTFOUND`,
          ],
        });
        session = await browserSession(browser);
        continue;
      }
      try {
        process.stdout.write(
          JSON.stringify(await session.read(request.url, request)) + "\n",
        );
      } catch (error) {
        process.stdout.write(JSON.stringify({ error: error.message }) + "\n");
        break;
      }
    }
  } catch {
    process.stdout.write(
      JSON.stringify({
        error:
          "Could not start the Fuzu browser. Run npm ci and npx playwright install --with-deps chromium; check NODE_BINARY, CHROMIUM_PATH and the display if using a visible browser.",
      }) + "\n",
    );
  } finally {
    clearTimeout(lifetime);
    input.close();
    await browser?.close();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href)
  await main();
