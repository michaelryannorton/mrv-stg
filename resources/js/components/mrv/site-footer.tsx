import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { flatPages, SITE_NAV, type NavItem, type NavPage } from './navigation';
import { pageHref } from './site-header';
import { SOCIAL_LINKS, type SocialLink } from './social-links';

/** State of the small data-status indicator in the footer's bottom row (production: SiteChrome.setDataStatus). */
export interface DataStatus {
    /** '' is the normal green dot (production passes an empty string). */
    state: '' | 'loading' | 'error';
    message: string;
}

interface SiteFooterProps {
    pages?: NavItem[];
    activeId?: string;
    /** Hidden until a page sets it. */
    dataStatus?: DataStatus | null;
}

function SocialIcon({ link }: { link: SocialLink }) {
    return (
        <a href={link.href} className="social-icon" aria-label={link.name} title={link.name}>
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" dangerouslySetInnerHTML={{ __html: link.svg }} />
        </a>
    );
}

function ExploreItem({ page, activeId }: { page: NavPage; activeId?: string }) {
    if (!page.ready) {
        return (
            <li>
                <span className="footer-nav-soon">{page.label}</span>
            </li>
        );
    }

    return (
        <li>
            <Link href={pageHref(page)} className={page.id === activeId ? 'active' : undefined}>
                {page.label}
            </Link>
        </li>
    );
}

/**
 * The footer: wordmark and tagline, social icons, the Explore list in two columns, the Mailchimp signup and
 * the copyright row. Same markup as production's public_html/js/nav.js. The signup form posts straight to the
 * organization's Mailchimp list in a new tab, exactly as on production.
 */
export default function SiteFooter({ pages = SITE_NAV, activeId, dataStatus = null }: SiteFooterProps) {
    const { public_channels: hasChannels } = usePage<SharedData>().props;

    const socialMid = Math.ceil(SOCIAL_LINKS.length / 2);
    const explore = flatPages(pages).filter((page) => page.requires !== 'public_channels' || hasChannels);
    const exploreMid = Math.ceil(explore.length / 2);

    return (
        <footer id="site-footer" className="site-footer">
            <div className="footer-grid mrv-container">
                <div className="footer-col footer-brand">
                    <Link href={route('home')} className="site-wordmark">
                        <span>
                            Mojave River <span className="wordmark-accent">Valley</span>
                        </span>
                    </Link>
                    <p className="card-note" style={{ marginTop: '10px' }}>
                        Celebrating the beauty, history, and people of the Mojave High Desert region.
                    </p>
                    <div className="social-icons">
                        <div className="social-icons-row">
                            {SOCIAL_LINKS.slice(0, socialMid).map((link) => (
                                <SocialIcon key={link.name} link={link} />
                            ))}
                        </div>
                        <div className="social-icons-row">
                            {SOCIAL_LINKS.slice(socialMid).map((link) => (
                                <SocialIcon key={link.name} link={link} />
                            ))}
                        </div>
                    </div>
                </div>

                <div className="footer-col">
                    <h4>Explore</h4>
                    <div className="footer-nav-columns">
                        <ul className="footer-nav-list">
                            {explore.slice(0, exploreMid).map((page) => (
                                <ExploreItem key={page.id} page={page} activeId={activeId} />
                            ))}
                        </ul>
                        <ul className="footer-nav-list">
                            {explore.slice(exploreMid).map((page) => (
                                <ExploreItem key={page.id} page={page} activeId={activeId} />
                            ))}
                        </ul>
                    </div>
                </div>

                <div className="footer-col">
                    <h4>Subscribe</h4>
                    <p className="card-note">Stay in the know about local events and resources.</p>
                    <div id="mc_embed_shell">
                        <div id="mc_embed_signup">
                            <form
                                action="https://mojaverivervalley.us16.list-manage.com/subscribe/post?u=51884090859aea7e44c46514a&id=3a9ea2ab1c&f_id=00296ce0f0"
                                method="post"
                                id="mc-embedded-subscribe-form"
                                name="mc-embedded-subscribe-form"
                                className="validate footer-mc-form"
                                target="_blank"
                                noValidate
                            >
                                <div className="mc-field-group">
                                    <label htmlFor="mce-EMAIL">
                                        Email address <span className="asterisk">*</span>
                                    </label>
                                    <input type="email" name="EMAIL" className="required email" id="mce-EMAIL" required defaultValue="" />
                                </div>
                                <div className="mc-field-group">
                                    <label htmlFor="mce-FNAME">First name</label>
                                    <input type="text" name="FNAME" className="text" id="mce-FNAME" defaultValue="" />
                                </div>
                                <div className="mc-field-group">
                                    <label htmlFor="mce-LNAME">Last name</label>
                                    <input type="text" name="LNAME" className="text" id="mce-LNAME" defaultValue="" />
                                </div>
                                <div id="mce-responses" className="clear">
                                    <div className="response" id="mce-error-response" style={{ display: 'none' }}></div>
                                    <div className="response" id="mce-success-response" style={{ display: 'none' }}></div>
                                </div>
                                <div aria-hidden="true" style={{ position: 'absolute', left: '-5000px' }}>
                                    <input type="text" name="b_51884090859aea7e44c46514a_3a9ea2ab1c" tabIndex={-1} defaultValue="" />
                                </div>
                                <button type="submit" name="subscribe" id="mc-embedded-subscribe" className="btn-primary">
                                    Subscribe
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div className="footer-bottom mrv-container">
                <div>&copy; {new Date().getFullYear()} Mojave River Valley. Weather data sourced via Open Meteo.</div>
                <div id="data-status" className={dataStatus ? `data-status ${dataStatus.state}` : 'data-status hidden'}>
                    <span className="dot"></span>
                    <span>{dataStatus?.message ?? ''}</span>
                </div>
            </div>
        </footer>
    );
}
