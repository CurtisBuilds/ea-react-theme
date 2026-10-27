/**
 * src/main.jsx — bundle entry point.
 *
 * WordPress decides the URL/page; this just renders the matching React component
 * based on the `data-page` attribute that the PHP template puts on #ea-react-root
 * (see front-page.php / page.php / index.php).
 *
 * To add a page: create src/pages/MyPage.jsx, import it here, add it to PAGES,
 * then create a WordPress Page whose slug matches the key.
 */
import { createRoot } from 'react-dom/client';
import HomePage from './pages/HomePage.jsx';
import FAQPage  from './pages/FAQ.jsx';
import BlankContentPage from './pages/BlankContentPage.jsx';
import LeagueHubPage from './pages/LeagueHubPage.jsx';
import CityProgramsPage from './pages/CityProgramsPage.jsx';
import FreeTrialLandingPage from './pages/FreeTrialLandingPage.jsx';

const PAGES = {
  home:      HomePage,
  faq:       FAQPage,
  blank:     BlankContentPage,
  leagueHub: LeagueHubPage,
  cityPrograms: CityProgramsPage,
  freeTrialLanding: FreeTrialLandingPage,
};

const el = document.getElementById('ea-react-root');
if (el) {
  // Server-rendered program list (inc/ea-ssr.php). React replaces the root's
  // markup on mount, so keep the list to show while the live feed loads.
  const ssrList = el.querySelector('[data-ea-ssr-list]');
  if (ssrList) window.__EA_SSR_LIST = ssrList.innerHTML;
  const slug = el.dataset.page || 'home';
  const Page = PAGES[slug] || HomePage;   // unknown slug → fall back to the home page
  createRoot(el).render(<Page />);
}
