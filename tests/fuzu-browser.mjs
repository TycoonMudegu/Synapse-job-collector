import { test } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";
import { browserSession, publicPage } from "../scripts/fuzu-browser.mjs";

const origin = "https://www.fuzu.com";
const category = `${origin}/kenya/job/computers-software-development`;
const detail = `${origin}/kenya/jobs/browser-fixture`;

async function fixture(t, handler) {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || undefined,
    args: ["--no-sandbox", "--disable-dev-shm-usage"],
  });
  t.after(() => browser.close());
  // Install fixture responses beneath the production request guard.
  const original = browser.newContext.bind(browser);
  browser.newContext = async (options) => {
    const context = await original(options);
    await context.route("**/*", handler);
    return context;
  };
  return browserSession(browser);
}

function respond(route, body, options = {}) {
  return route.fulfill({
    status: 200,
    contentType: "text/html",
    body,
    ...options,
  });
}

test("browser entry points exclude credentials, foreign hosts, queries and login", () => {
  for (const url of [
    `${origin}/robots.txt`,
    `${origin}/kenya/job`,
    category,
    detail,
  ])
    assert.equal(publicPage(url), true);
  for (const url of [
    "https://www.fuzu.com.attacker.test/kenya/job",
    "https://user@www.fuzu.com/kenya/job",
    `${origin}:8443/kenya/job`,
    `${origin}/login`,
    `${origin}/kenya/job?q=developer`,
    `${detail}#fragment`,
    "http://www.fuzu.com/kenya/job",
  ])
    assert.equal(publicPage(url), false);
});

test("one Chromium context renders JavaScript vacancies and retains session cookies", async (t) => {
  const seen = [];
  const session = await fixture(t, async (route) => {
    const request = route.request();
    seen.push({ url: request.url(), cookie: request.headers().cookie });
    if (request.url().endsWith("/robots.txt"))
      return respond(route, "User-agent: *\nDisallow: /api/", {
        contentType: "text/plain",
      });
    if (request.url() === detail)
      return respond(
        route,
        '<script>setTimeout(() => { const node = document.createElement("script"); node.type = "application/ld+json"; node.textContent = JSON.stringify({"@type":"JobPosting",title:"Fixture engineer",hiringOrganization:{name:"Fixture employer"},description:"Fixture duties"}); document.head.append(node); }, 50);</script>',
      );
    return respond(
      route,
      '<title>Fixture public jobs</title><script>document.cookie="fixture_session=retained; Path=/; Secure"; setTimeout(() => { document.body.innerHTML = \'<a href="/kenya/jobs/browser-fixture">Fixture engineer</a>\'; }, 50);</script>',
    );
  });
  await assert.rejects(
    () => session.read(`${origin}/kenya/job`),
    /robots permissions/,
  );
  await session.read(`${origin}/robots.txt`);
  assert.match(
    (await session.read(`${origin}/kenya/job`)).body,
    /href="\/kenya\/jobs\/browser-fixture"/,
  );
  await session.read(category);
  assert.match((await session.read(detail)).body, /"@type":"JobPosting"/);
  assert.deepEqual(
    seen.map((item) => item.url),
    [`${origin}/robots.txt`, `${origin}/kenya/job`, category, detail],
  );
  assert.match(seen.at(-1).cookie, /fixture_session=retained/);
});

test("HTTP 403 and 429 return their status without waiting for vacancy data", async (t) => {
  const seen = [];
  const session = await fixture(t, async (route) => {
    seen.push(route.request().url());
    if (route.request().url().endsWith("/robots.txt"))
      return respond(route, "", { status: 404 });
    return respond(
      route,
      '<title>Just a moment...</title><script src="/challenge.js"></script>',
      { status: route.request().url() === category ? 429 : 403 },
    );
  });
  await session.read(`${origin}/robots.txt`);
  assert.deepEqual(await session.read(`${origin}/kenya/job`), {
    status: 403,
    body: "",
  });
  assert.deepEqual(await session.read(category), { status: 429, body: "" });
  assert.equal(seen.filter((url) => publicPage(url)).length, 3);
});

test("redirects cannot reach login pages or private network targets", async (t) => {
  const seen = [];
  const session = await fixture(t, async (route) => {
    seen.push(route.request().url());
    if (route.request().url().endsWith("/robots.txt"))
      return respond(route, "", { status: 404 });
    return respond(route, "", {
      status: 302,
      headers: { location: "https://127.0.0.1/private" },
    });
  });
  await session.read(`${origin}/robots.txt`);
  await assert.rejects(
    () => session.read(`${origin}/kenya/job`),
    /Redirects and login pages are not followed/,
  );
  assert.deepEqual(seen, [`${origin}/robots.txt`, `${origin}/kenya/job`]);
});

test("robots exclusions, off-site subresources and page size limits apply inside Chromium", async (t) => {
  const seen = [];
  const session = await fixture(t, async (route) => {
    seen.push(route.request().url());
    if (route.request().url().endsWith("/robots.txt"))
      return respond(
        route,
        "User-agent: *\nDisallow: /api/*\nDisallow: /kenya/jobs/blocked$\nDisallow: /*?*from_apply=",
      );
    return respond(
      route,
      '<a href="/kenya/jobs/browser-fixture">Fixture engineer</a><script>fetch("/api/fixture").catch(() => {}); fetch("/track?from_apply=fixture").catch(() => {});</script><img src="https://127.0.0.1/private"><iframe src="/kenya/jobs/iframe"></iframe>',
    );
  });
  await session.read(`${origin}/robots.txt`);
  await session.read(`${origin}/kenya/job`);
  await assert.rejects(
    () => session.read(category, { maxBytes: 32 }),
    /size limit/,
  );
  await assert.rejects(
    () => session.read(`${origin}/kenya/jobs/blocked`),
    /navigation failed/,
  );
  assert.deepEqual(seen, [
    `${origin}/robots.txt`,
    `${origin}/kenya/job`,
    category,
  ]);
});

test("a challenge returned with HTTP 200 produces a clear failure", async (t) => {
  const session = await fixture(t, async (route) =>
    respond(
      route,
      route.request().url().endsWith("/robots.txt")
        ? "User-agent: *"
        : "<title>Just a moment...</title>",
    ),
  );
  await session.read(`${origin}/robots.txt`);
  await assert.rejects(
    () => session.read(`${origin}/kenya/job`),
    /requires a browser security check/,
  );
});
