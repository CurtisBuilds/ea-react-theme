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
import CityProgramsFreeTrialPage from './pages/CityProgramsFreeTrialPage.jsx';
import BasketballGuidePage from './pages/BasketballGuidePage.jsx';
import BasketballRepTryoutsPage from './pages/BasketballRepTryoutsPage.jsx';
import ComingSoonPage from './pages/ComingSoonPage.jsx';
import DirectoryHomePage from './pages/DirectoryHomePage.jsx';
import CommunityPartnershipsPage from './pages/CommunityPartnershipsPage.jsx';
import CampsPage from './pages/CampsPage.jsx';
import VolleyballPage from './pages/VolleyballPage.jsx';
import RepDevelopmentTeamsPage from './pages/RepDevelopmentTeamsPage.jsx';
import TestPage from './pages/TestPage.jsx';

const PAGES = {
  home:      HomePage,
  faq:       FAQPage,
  blank:     BlankContentPage,
  leagueHub: LeagueHubPage,
  cityPrograms: CityProgramsPage,
  freeTrialLanding: FreeTrialLandingPage,
  cityProgramsFreeTrial: CityProgramsFreeTrialPage,
  basketballGuide: BasketballGuidePage,
  basketballRepTryouts: BasketballRepTryoutsPage,
  comingSoon: ComingSoonPage,
  directoryHome: DirectoryHomePage,
  communityPartnerships: CommunityPartnershipsPage,
  partnerships: CommunityPartnershipsPage,
  'community-partnerships': CommunityPartnershipsPage,
  camps: CampsPage,
  volleyball: VolleyballPage,
  repDevelopmentTeams: RepDevelopmentTeamsPage,
  'rep-development-teams': RepDevelopmentTeamsPage,
  'rep-development': RepDevelopmentTeamsPage,
  'development-teams': RepDevelopmentTeamsPage,
  testPage: TestPage,
  'test-page': TestPage,
};

const el = document.getElementById('ea-react-root');
if (el) {
  const slug = el.dataset.page || 'home';
  const Page = PAGES[slug] || HomePage;   // unknown slug → fall back to the home page
  createRoot(el).render(<Page />);
}
