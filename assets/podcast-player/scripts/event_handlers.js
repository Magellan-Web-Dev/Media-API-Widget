// Sanitize a feed-supplied image URL before it is interpolated into CSS.
// Allows only http(s) / protocol-relative / root-relative URLs and strips the
// characters that could break out of a double-quoted CSS url("...") value.

function mawSanitizeImageUrl(url) {
    if (typeof url !== "string") {
        return "";
    }
    const trimmed = url.trim();
    if (!/^(https?:\/\/|\/\/|\/)/i.test(trimmed)) {
        return "";
    }
    return trimmed.replace(/["\\\r\n]/g, "");
}

// Playback Progress Rendering
//
// Progress is drawn from requestAnimationFrame straight off audio.currentTime
// so the filler tracks playback every frame instead of stepping once per timer
// tick. The filler is moved with transform: translateX() (see style.css) so the
// compositor can move it without re-laying out the bar.

const PROGRESS_RESET_MS = 80;

const prefersReducedMotion = typeof window.matchMedia === "function"
    ? window.matchMedia("(prefers-reduced-motion: reduce)").matches
    : false;

let progressRafId = null;

let progressResetTimer = null;

let progressResetting = false;

// Clamped 0-1 play position. Duration is unknown (NaN), zero, or Infinity for
// streamed sources and before metadata arrives, so guard the division to keep
// NaN / Infinity out of the CSS.

function getProgressRatio() {
    const duration = audio.duration;
    if (!Number.isFinite(duration) || duration <= 0) {
        return 0;
    }
    const ratio = audio.currentTime / duration;
    if (!Number.isFinite(ratio)) {
        return 0;
    }
    return Math.min(Math.max(ratio, 0), 1);
}

// The filler is a full width pill slid left out of the track (see style.css),
// so 0 is translateX(-100%) and 1 is translateX(0). Clamped here as well as at
// the callers, since this is the only place that writes the style.

function renderProgressBar(ratio) {
    const safeRatio = Number.isFinite(ratio) ? Math.min(Math.max(ratio, 0), 1) : 0;
    progressFiller.style.transform = `translateX(${(safeRatio - 1) * 100}%)`;
}

function stopProgressAnimation() {
    if (progressRafId !== null) {
        cancelAnimationFrame(progressRafId);
        progressRafId = null;
    }
}

// Always cancels the previous loop first so play/seek/source changes can call
// this freely without stacking loops.

function startProgressAnimation() {
    stopProgressAnimation();

    const step = () => {
        progressRafId = requestAnimationFrame(step);
        if (progressResetting) {
            return;
        }
        renderProgressBar(getProgressRatio());
        setCurrentPlayTime();
    };

    progressRafId = requestAnimationFrame(step);
}

// Drops an in-flight reset so a seek paints at the new position right away
// instead of easing there.

function cancelProgressReset() {
    clearTimeout(progressResetTimer);
    progressResetTimer = null;
    progressResetting = false;
    progressFiller.classList.remove("progress-resetting");
}

function renderProgressBarImmediate(ratio) {
    cancelProgressReset();
    renderProgressBar(ratio);
}

// Runs the short slide from the finished position back to zero before the next
// episode starts painting. progressResetting keeps the rAF loop from writing
// over the transition while it plays.

function resetProgressBar() {
    stopProgressAnimation();
    cancelProgressReset();

    if (prefersReducedMotion) {
        renderProgressBar(0);
        return;
    }

    progressResetting = true;
    progressFiller.classList.add("progress-resetting");

    // Flush the added class so the transition starts from the current scale.

    void progressFiller.offsetWidth;

    renderProgressBar(0);

    progressResetTimer = setTimeout(() => {
        progressResetTimer = null;
        progressResetting = false;
        progressFiller.classList.remove("progress-resetting");
    }, PROGRESS_RESET_MS);
}

// Tie the loop to the element's own playback state so it can never outlive
// playback or double up.

audio.addEventListener("play", startProgressAnimation);

audio.addEventListener("pause", stopProgressAnimation);

audio.addEventListener("emptied", stopProgressAnimation);

audio.addEventListener("ended", () => {
    stopProgressAnimation();
    if (!progressResetting) {
        renderProgressBar(1);
    }
});

// Covers seeks made while paused, and any clamping the browser applied.

audio.addEventListener("seeked", () => {
    if (!progressResetting) {
        renderProgressBar(getProgressRatio());
    }
});

// Set Current Play Time

let lastPlayTimeText = "";

function setEpisodeTimeText(text) {
    if (text !== lastPlayTimeText) {
        lastPlayTimeText = text;
        episodeTime.innerText = text;
    }
}

function setCurrentPlayTime() {
    if (audio.currentTime >= audio.duration) {
        return;
    }
    let totalCurrentTime = audio.currentTime;
    let hours = Math.floor(totalCurrentTime / 3600);
    hours = hours < 10 ? `0${hours}` : hours;
    if (hours > 59) {
        hours = `00`;
    }
    totalCurrentTime = totalCurrentTime - (Number(hours) * 3600);
    let minutes = Math.floor(totalCurrentTime / 60);
    minutes = minutes < 10 ? `0${minutes}` : minutes;
    if (minutes > 59) {
        minutes = `00`;
        hours = Number(hours) + 1;
    }
    totalCurrentTime = totalCurrentTime - (Number(minutes) * 60);
    let seconds = Math.round(totalCurrentTime);
    seconds = seconds < 10 ? `0${seconds}` : seconds;
    if (seconds > 59) {
        seconds = `00`;
        minutes++;
        minutes = minutes < 10 ? `0${minutes}` : minutes;
        if (minutes > 59) {
            minutes = `00`;
            hours = Number(hours) + 1;
        }
    }
    hours = Number(hours) < 10 ? `0${Number(hours)}` : hours;
    setEpisodeTimeText(audio.duration > 3600 ? `${hours}:${minutes}:${seconds}` : audio.duration > 60 ? `${minutes}:${seconds}` : `00:${seconds}`);
}

// Toggle Play

let playing = true;

function togglePlay(starting) {
    if (starting) {
        playing = true;
    } else {
        playing = !playing;
    }
    if (playing) {
        const playRequest = audio.play();
        Promise.resolve(playRequest).then(() => {
            playButtonIcon.classList.add("playing-active");
            startProgressAnimation();
        }).catch(() => {
            // Browsers may reject audible autoplay despite a user opening the
            // lightbox. Leave the player paused and ready for a manual click.
            playing = false;
            playButtonIcon.classList.remove("playing-active");
            stopProgressAnimation();
        });
    } else {
        playButtonIcon.classList.remove("playing-active");
        audio.pause();
        stopProgressAnimation();
    }
}

// Play Button Click Handler

playButtonIcon.addEventListener("click", () => togglePlay(false));

// Pause/Play On Space Bar Or Advance Play Time From Left Or Right Arrows

window.addEventListener("keyup", e => {

    switch(e.code) {

        // Play Pause Space Bar

        case 'Space':
            togglePlay();
            break;
    }
});

// Seek by a relative offset and paint the bar right away, so the move is
// visible even while paused.

function seekBy(offsetSeconds) {
    const duration = audio.duration;
    const maxTime = Number.isFinite(duration) && duration > 0 ? duration : Infinity;
    audio.currentTime = Math.min(Math.max(audio.currentTime + offsetSeconds, 0), maxTime);
    renderProgressBarImmediate(getProgressRatio());
    setCurrentPlayTime();
}

window.addEventListener("keydown", e => {

    switch(e.code) {

        // Arrow Left Rewind One Second

        case 'ArrowLeft':
            seekBy(-1);
            break;

        // Arrow Right Advance One Second

        case 'ArrowRight':
            seekBy(1);
            break;
    }
});

// Set Progress Bar

function setProgressBar(e) {
    const totalBarWidth = progressBar.clientWidth;
    if (!totalBarWidth) {
        return;
    }
    const barPercentage = Math.min(Math.max(e.offsetX / totalBarWidth, 0), 1);
    renderProgressBarImmediate(barPercentage);
    const duration = audio.duration;
    if (Number.isFinite(duration) && duration > 0) {
        audio.currentTime = barPercentage * duration;
    }
    setCurrentPlayTime();
}

// Handle Click, Mouse Down, Mouse Move And Mouse Up On Progress Bar

progressBar.addEventListener("click", setProgressBar);
progressBar.addEventListener("mousedown", (e) => {
    progressBar.addEventListener("mousemove", setProgressBar, true)
});
window.addEventListener("mouseup", () => {
    progressBar.removeEventListener("mousemove", setProgressBar, true);
});

// Episode Description Marquee
//
// The description element is the marquee track (see style.css): it holds two
// identical copies of the text and is slid left by exactly one copy's advance -
// the copy's width plus the gap between the copies - with a linear CSS
// animation. At the loop point the second copy is sitting precisely where the
// first one started, so the reset cannot be seen. Only transform is animated,
// which keeps the scroll on the compositor; nothing here writes left, margins
// or any other layout property.
//
// The marquee is only switched on when the text is genuinely wider than the
// visible window. Shorter descriptions stay where they are and their duplicate
// stays out of the layout.

// The old timer moved 1px every 28ms, i.e. ~36px per second.

const DESCRIPTION_SCROLL_PX_PER_SECOND = 36;

// Pause before the first pass. CSS animation-delay only applies ahead of the
// first iteration, so the repetitions after it run on without another pause.

const DESCRIPTION_SCROLL_DELAY_MS = 5000;

const descriptionContainer = episodeDescription.parentElement;

const descriptionCopies = episodeDescription.querySelectorAll(".episode-description-copy");

const descriptionPrimaryCopy = descriptionCopies.length > 0 ? descriptionCopies[0] : null;

const descriptionDuplicateCopy = descriptionCopies.length > 1 ? descriptionCopies[1] : null;

// When the current pass is due to start moving. A recalculation that lands
// during the initial delay carries the remainder over instead of restarting the
// full five seconds.

let descriptionScrollBeginsAt = 0;

// Last measurement, so a resize that changes nothing the marquee depends on
// does not restart the animation.

let lastDescriptionCopyWidth = -1;

let lastDescriptionShouldScroll = false;

let descriptionRefreshRafId = null;

function descriptionMarqueeAvailable() {
    return !prefersReducedMotion
        && descriptionContainer !== null
        && descriptionPrimaryCopy !== null
        && descriptionDuplicateCopy !== null;
}

// Width the text is actually visible across: the container's content box. Its
// horizontal padding is only there to give the edge mask room to fade.

function getDescriptionViewportWidth() {
    const styles = window.getComputedStyle(descriptionContainer);
    const paddingLeft = parseFloat(styles.paddingLeft);
    const paddingRight = parseFloat(styles.paddingRight);
    const padding = (Number.isFinite(paddingLeft) ? paddingLeft : 0) + (Number.isFinite(paddingRight) ? paddingRight : 0);
    return descriptionContainer.clientWidth - padding;
}

// A copy's own width never changes with the gap or with the transform, so this
// is safe to read while the marquee is running. 1px of slack keeps sub-pixel
// rounding from starting a scroll nobody asked for.

function isDescriptionOverflowing(copyWidth, viewportWidth) {
    return copyWidth > 0 && viewportWidth > 0 && copyWidth > viewportWidth + 1;
}

// Re-measures and (re)starts the marquee from its starting position. delayMs is
// how long to wait before the first pass.

function applyDescriptionMarquee(delayMs) {
    const safeDelay = Math.max(delayMs, 0);

    descriptionScrollBeginsAt = performance.now() + safeDelay;

    // Dropping both classes returns the track to translate(0) and takes the
    // duplicate back out of the layout.

    episodeDescription.classList.remove("description-scrolling");
    episodeDescription.classList.remove("description-duplicated");

    if (!descriptionMarqueeAvailable()) {
        lastDescriptionCopyWidth = -1;
        lastDescriptionShouldScroll = false;
        return;
    }

    // Reading a rect flushes the pending class removal, so re-adding the class
    // below starts a brand new animation rather than resuming the old one.

    const copyWidth = descriptionPrimaryCopy.getBoundingClientRect().width;
    const viewportWidth = getDescriptionViewportWidth();
    const shouldScroll = isDescriptionOverflowing(copyWidth, viewportWidth);

    lastDescriptionCopyWidth = copyWidth;
    lastDescriptionShouldScroll = shouldScroll;

    if (!shouldScroll) {
        return;
    }

    // Show the duplicate before measuring the distance, so the one character
    // gap between the copies (see style.css) is part of it. Measuring copy to
    // copy rather than adding numbers up keeps the distance exact even with
    // fractional text and gap widths, and a uniform transform cancels out of
    // the subtraction.

    episodeDescription.classList.add("description-duplicated");

    const distance = descriptionDuplicateCopy.getBoundingClientRect().left - descriptionPrimaryCopy.getBoundingClientRect().left;

    if (!(distance > 0)) {
        episodeDescription.classList.remove("description-duplicated");
        lastDescriptionShouldScroll = false;
        return;
    }

    // Duration from distance, so the speed in pixels per second is the same
    // whatever the description's length.

    episodeDescription.style.setProperty("--description-scroll-distance", `${distance}px`);
    episodeDescription.style.setProperty("--description-scroll-duration", `${distance / DESCRIPTION_SCROLL_PX_PER_SECOND}s`);
    episodeDescription.style.setProperty("--description-scroll-delay", `${safeDelay}ms`);

    episodeDescription.classList.add("description-scrolling");
}

// Episode change: back to the start of the new text, with the full delay again.

function resetDescriptionMarquee() {
    applyDescriptionMarquee(DESCRIPTION_SCROLL_DELAY_MS);
}

// Layout/font change: only restart if something the marquee depends on actually
// moved, otherwise a drag-resize would keep yanking the text back to the start.
// Any leftover initial delay is carried over.

function refreshDescriptionMarquee() {
    if (!descriptionMarqueeAvailable()) {
        return;
    }

    const copyWidth = descriptionPrimaryCopy.getBoundingClientRect().width;
    const viewportWidth = getDescriptionViewportWidth();
    const shouldScroll = isDescriptionOverflowing(copyWidth, viewportWidth);

    if (shouldScroll === lastDescriptionShouldScroll && Math.abs(copyWidth - lastDescriptionCopyWidth) < 0.5) {
        return;
    }

    applyDescriptionMarquee(descriptionScrollBeginsAt - performance.now());
}

// Coalesces bursts of resize/font notifications into one measurement per frame.

function scheduleDescriptionRefresh() {
    if (descriptionRefreshRafId !== null) {
        return;
    }
    descriptionRefreshRafId = requestAnimationFrame(() => {
        descriptionRefreshRafId = null;
        refreshDescriptionMarquee();
    });
}

// Writes the episode's description into both copies and restarts the marquee.
// textContent, never innerHTML: the text comes from the feed.

function setEpisodeDescriptionText(text) {
    const description = typeof text === "string" ? text : "";

    if (descriptionPrimaryCopy === null) {
        episodeDescription.textContent = description;
        return;
    }

    descriptionPrimaryCopy.textContent = description;

    if (descriptionDuplicateCopy !== null) {
        descriptionDuplicateCopy.textContent = description;
    }

    resetDescriptionMarquee();
}

// A single observer for the life of the player, so nothing accumulates.

if (descriptionContainer !== null) {
    if (typeof ResizeObserver === "function") {
        new ResizeObserver(scheduleDescriptionRefresh).observe(descriptionContainer);
    } else {
        window.addEventListener("resize", scheduleDescriptionRefresh);
    }
}

// A webfont arriving after the first measurement changes the text width, so
// re-check once the font set settles.

if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === "function") {
    document.fonts.ready.then(scheduleDescriptionRefresh);

    if (typeof document.fonts.addEventListener === "function") {
        document.fonts.addEventListener("loadingdone", scheduleDescriptionRefresh);
    }
}

window.addEventListener("load", scheduleDescriptionRefresh);

// Initialize On Load / Click On Episode List Item Event Handler

let currentEpisodeData;

// Bumped on every source change. A stale one-shot "loadedmetadata" listener
// left over from a source that never loaded compares tokens and bails, so only
// the newest source change ever runs its handler.

let sourceChangeToken = 0;

function setTotalEpisodeTime(item, event) {

    switch(event) {
        case 'init':
            currentEpisodeData = episodes.find(episode => episode.guid === startingEpisodeId);
            break;
        case 'click':
            currentEpisodeData = episodes.find(episode => episode.guid === item.dataset.episodeid);
            break;
        case 'autoadvance':
            currentEpisodeData = item;
            break;
        default:
            currentEpisodeData = null;
    }

    if (currentEpisodeData !== null && currentEpisodeData !== undefined) {

        // Highlight List Item That Is Set To Be Played

        episodeListItemElements.forEach(element => {
            if (element.dataset.episodeid === currentEpisodeData.guid) {
                element.classList.add("list-item-active");
            } else {
                element.classList.remove("list-item-active");
            }
        });

        // Set Image if episode has its own image
        if (currentEpisodeData.image) {
            const safeImageUrl = mawSanitizeImageUrl(currentEpisodeData.image);

            if (safeImageUrl) {
                // Set Image Href

                currentEpisodeImage.src = safeImageUrl;

                // Set Background Image (textContent on a <style> element; the URL
                // is scheme-validated and break-out characters are stripped)

                backgroundImage.textContent = `
                    body::before {
                        background-image: url("${safeImageUrl}");
                    }
                `;
            }
        }

        // Set Title Text

        episodeSelectedTitle.innerText = currentEpisodeData.title;

        // Set Description Text
        //
        // Fills both marquee copies, re-measures the new text against the
        // container and returns the track to its starting position.

        setEpisodeDescriptionText(typeof currentEpisodeData.description === 'object' ? '' : currentEpisodeData.description);

        // Run the short slide back to zero before the new source is attached.
        // resetProgressBar() also stops the running loop, so nothing repaints
        // the old position while the reset plays.

        resetProgressBar();

        const currentSourceChange = ++sourceChangeToken;

        audio.src = currentEpisodeData.enclosure['@attributes'].url;

        // Set Time Of Episode

        audio.addEventListener("loadedmetadata", () => {

            if (currentSourceChange !== sourceChangeToken) {
                return;
            }

            let totalDurationTime = audio.duration;

            let totalHours = Math.floor(totalDurationTime / 3600);

            if (totalHours > 0) {
                totalDurationTime = totalDurationTime - (totalHours * 3600);
            }

            totalHours = totalHours < 10 ? `0${totalHours}` : totalHours;

            let totalMinutes = Math.floor(totalDurationTime / 60);

            if (totalMinutes > 0) {
                totalDurationTime = totalDurationTime - (totalMinutes * 60);
            }

            let totalSeconds;

            if (Math.round(totalDurationTime) === 60) {
                totalSeconds = `00`;
                totalMinutes++;
            } else {
                totalSeconds = totalDurationTime < 10 ? `0${Math.round(totalDurationTime)}` : Math.round(totalDurationTime);
            }

            totalMinutes = totalMinutes < 10 ? `0${totalMinutes}` : totalMinutes;

            const hasHours = Number(totalHours) > 0;

            episodeDuration.innerText = hasHours ? `${totalHours}:${totalMinutes}:${totalSeconds}` : `${totalMinutes}:${totalSeconds}`;

            episodeTime.style.width = hasHours ? '7.3ch' : '5ch';

            if (event === 'init') {
                setEpisodeTimeText(hasHours ? `00:00:00` : `00:00`);
            }

            if (event === 'init') {
                togglePlay(autoplayEnabled);
            } else {
                togglePlay(true);
            }

            // The delay is already running from when the description was set;
            // this only picks up any layout change the metadata caused (the
            // play time column is re-sized just above).

            refreshDescriptionMarquee();
        }, { once: true });
    }
}

if (fullPlayer) {
    episodeListContainer.addEventListener("click", e => {
        const listItemClicked = e.target.closest("li");
        if (listItemClicked) {
            setTotalEpisodeTime(listItemClicked, 'click');
        }
    });
}

setTotalEpisodeTime(null, 'init');

// Advance To Next Episode When Current One Finishes

    audio.addEventListener("ended", () => {
        if (fullPlayer) {
            const nextEpisodeIndex = episodes.findIndex(episode => episode.guid === currentEpisodeData.guid) + 1;
            if ((episodes.length - 1) >= nextEpisodeIndex) {
                setTimeout(() => {
                    setTotalEpisodeTime(episodes[nextEpisodeIndex], 'autoadvance');
                }, 1000)
            }
        } else {
            playButtonIcon.classList.remove("playing-active");
            playing = false;
            audio.currentTime = 0;
            resetProgressBar();
        }
    });
