/**
 * src/pages/TestPage.jsx
 *
 * A lightweight sandbox template with editable Customizer content.
 */
import { useEffect, useState } from 'react';
import { Layout, FB, getThemeData, useViewport, sectionLinkAttrs } from '../lib/shared.jsx';

const CUSTOMIZER_SETTINGS = {
  ea_test_page_eyebrow: 'eyebrow',
  ea_test_page_heading: 'heading',
  ea_test_page_lead: 'lead',
  ea_test_page_button_label: 'buttonLabel',
  ea_test_page_button_url: 'buttonUrl',
  ea_test_page_section_heading: 'sectionHeading',
  ea_test_page_section_body: 'sectionBody',
  ea_test_page_card_1_heading: 'card1Heading',
  ea_test_page_card_1_body: 'card1Body',
  ea_test_page_card_1_image: 'card1Image',
  ea_test_page_card_2_heading: 'card2Heading',
  ea_test_page_card_2_body: 'card2Body',
  ea_test_page_card_2_image: 'card2Image',
};

function pick(value, fallback) {
  return value === undefined || value === null || value === '' ? fallback : value;
}

function imageBlock(src, alt, isMobile, ratio = '4 / 3') {
  if (src) {
    return (
      <img
        src={src}
        alt={alt}
        style={{
          width: '100%',
          aspectRatio: ratio,
          objectFit: 'cover',
          borderRadius: 8,
          display: 'block',
          border: '1px solid var(--border-card, #E5E5E5)',
        }}
      />
    );
  }

  return (
    <div
      aria-hidden="true"
      style={{
        width: '100%',
        aspectRatio: ratio,
        borderRadius: 8,
        border: '1px solid var(--border-card, #E5E5E5)',
        background: 'linear-gradient(135deg, #DDF6FF 0%, #F7FBFD 100%)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color: 'var(--ea-navy, #10414F)',
        fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
        fontSize: isMobile ? 13 : 15,
        fontWeight: 800,
      }}
    >
      Image Slot
    </div>
  );
}

function TestCard({ heading, body, image, isMobile }) {
  return (
    <article style={{ ...FB.card, padding: isMobile ? 18 : 22 }}>
      {imageBlock(image, heading, isMobile, '16 / 9')}
      <h3 style={{
        margin: isMobile ? '16px 0 8px' : '18px 0 8px',
        fontFamily: 'var(--font-display, "BBH Bogle", sans-serif)',
        fontSize: isMobile ? 26 : 30,
        lineHeight: 1.02,
        letterSpacing: '0.01em',
        color: 'var(--ea-navy, #10414F)',
        textTransform: 'uppercase',
      }}>
        {heading}
      </h3>
      <p style={{
        margin: 0,
        fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
        color: 'var(--ea-ink, #1E526E)',
        fontSize: isMobile ? 15 : 16,
        lineHeight: 1.5,
        whiteSpace: 'pre-line',
      }}>
        {body}
      </p>
    </article>
  );
}

export default function TestPage() {
  const { isMobile } = useViewport();
  const t = getThemeData();
  const [page, setPage] = useState(t.testPage || {});
  const buttonHref = pick(page.buttonUrl, '/programs/');
  const buttonLink = sectionLinkAttrs({ url: buttonHref });

  useEffect(() => {
    if (typeof window === 'undefined' || !window.wp || !window.wp.customize) {
      return undefined;
    }

    const cleanup = [];

    Object.entries(CUSTOMIZER_SETTINGS).forEach(([settingName, key]) => {
      window.wp.customize(settingName, (setting) => {
        const update = (value) => {
          setPage((current) => ({ ...current, [key]: value }));
        };

        update(setting.get());
        setting.bind(update);
        cleanup.push(() => setting.unbind(update));
      });
    });

    return () => cleanup.forEach((unbind) => unbind());
  }, []);

  const bodyStyle = {
    margin: 0,
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    color: 'var(--ea-ink, #1E526E)',
    fontSize: isMobile ? 16 : 18,
    lineHeight: 1.5,
    whiteSpace: 'pre-line',
  };

  return (
    <Layout>
      <main style={{ background: '#fff', minHeight: '100vh' }}>
        <section style={{
          width: 'min(100% - 32px, 1120px)',
          margin: '0 auto',
          padding: isMobile ? '42px 0 64px' : '72px 0 96px',
        }}>
          <div style={{
            maxWidth: 760,
          }}>
            <div>
              <p style={{
                margin: '0 0 10px',
                fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                color: 'var(--ea-blue, #0092DB)',
                fontSize: 14,
                fontWeight: 800,
                letterSpacing: '0.08em',
                textTransform: 'uppercase',
              }}>
                {pick(page.eyebrow, 'Test Template')}
              </p>
              <h1 style={{ ...FB.h(isMobile ? 46 : 68), margin: 0 }}>
                {pick(page.heading, 'EA Test Page')}
              </h1>
              <p style={{ ...bodyStyle, marginTop: isMobile ? 14 : 18, maxWidth: 640 }}>
                {pick(page.lead, 'Use this page to preview new layout ideas, image placements, buttons, and editable content before moving them into a live template.')}
              </p>
              {buttonHref && (
                <a
                  {...buttonLink}
                  style={{
                    ...FB.btn('primary'),
                    display: 'inline-flex',
                    marginTop: 24,
                    textDecoration: 'none',
                  }}
                >
                  {pick(page.buttonLabel, 'View Programs')}
                </a>
              )}
            </div>
          </div>

          <section style={{ marginTop: isMobile ? 48 : 72 }}>
            <h2 style={{ ...FB.h(isMobile ? 34 : 44), margin: 0 }}>
              {pick(page.sectionHeading, 'Editable Template Blocks')}
            </h2>
            <p style={{ ...bodyStyle, marginTop: 12, maxWidth: 760 }}>
              {pick(page.sectionBody, 'This area is intentionally simple. Swap the copy and images in the Customizer to test spacing, headings, and content structure without affecting other live pages.')}
            </p>
            <div style={{
              display: 'grid',
              gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, minmax(0, 1fr))',
              gap: isMobile ? 16 : 22,
              marginTop: isMobile ? 24 : 30,
            }}>
              <TestCard
                heading={pick(page.card1Heading, 'First Test Block')}
                body={pick(page.card1Body, 'Use this card for a small note, feature, CTA, or section idea.')}
                image={pick(page.card1Image, '')}
                isMobile={isMobile}
              />
              <TestCard
                heading={pick(page.card2Heading, 'Second Test Block')}
                body={pick(page.card2Body, 'This second card gives the template enough structure to test repeated content.')}
                image={pick(page.card2Image, '')}
                isMobile={isMobile}
              />
            </div>
          </section>
        </section>
      </main>
    </Layout>
  );
}
