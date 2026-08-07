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

// Episode Description Text Scroll Across Handling

let startDescriptionScroll;

let descriptionDelayStart;

function initDescriptionScrollText() {

    // Stop Existing Scrolling Of Description Text

    clearInterval(startDescriptionScroll);

    clearTimeout(descriptionDelayStart);

    descriptionDelayStart = setTimeout(() => {
        let descriptionTextOffset = 0;

        const descriptionWidth = episodeDescription.scrollWidth;
        const containerWidth = episodeDescription.parentNode.clientWidth;

        startDescriptionScroll = setInterval(() => {

            descriptionTextOffset--;

            episodeDescription.style.left = `${descriptionTextOffset}px`;

            if ((descriptionTextOffset * -1) >= (descriptionWidth + 24)) {
                descriptionTextOffset = containerWidth + 24;
                episodeDescription.style.left = `${descriptionTextOffset}px`
            }

        }, 28)
    }, 5000);
}

window.addEventListener("load", initDescriptionScrollText);

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

        episodeDescription.innerText = typeof currentEpisodeData.description === 'object' ? '' : currentEpisodeData.description;

        episodeDescription.style.left = `0px`;

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

            episodeDescription.style.left = '0px';

            if (event === 'init') {
                togglePlay(autoplayEnabled);
            } else {
                togglePlay(true);
            }
            
            initDescriptionScrollText();
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
