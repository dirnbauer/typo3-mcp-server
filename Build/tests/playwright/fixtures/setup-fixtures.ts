import { test as base, type Page, expect } from '@playwright/test';
import config from '../config';

export class BackendPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async gotoModule(path: string): Promise<void> {
    await this.page.goto(config.baseUrl + path);
    await this.page.waitForLoadState('networkidle');
  }

  async gotoMcpModule(): Promise<void> {
    await this.gotoModule('/typo3/module/user/mcp-server');
  }
}

type Fixtures = {
  backend: BackendPage;
};

export const test = base.extend<Fixtures>({
  backend: async ({ page }, use) => {
    await use(new BackendPage(page));
  },
});

export { expect };
