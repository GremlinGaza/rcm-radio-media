(() => {
  document.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-confirm]');
    if (button && !window.confirm(button.dataset.confirm)) {
      event.preventDefault();
      event.stopPropagation();
    }
  });

  const toggle = document.getElementById('menuToggle');
  const nav = document.getElementById('mainNav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    });
    nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
      nav.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }));
  }

  const setupContentFilters = (config) => {
    const items = Array.from(document.querySelectorAll(config.itemSelector));
    const search = document.querySelector(config.searchSelector);
    const status = document.querySelector(config.statusSelector);
    const empty = document.querySelector(config.emptySelector);
    const filters = Array.from(document.querySelectorAll(config.filterSelector));
    if (!items.length || !search || !filters.length) return;

    let activeCategory = 'all';
    const update = () => {
      const query = search.value.trim().toLocaleLowerCase();
      let visibleCount = 0;
      items.forEach((item) => {
        const categoryMatches = activeCategory === 'all' || item.dataset.category === activeCategory;
        const searchMatches = !query || (item.dataset.search || '').includes(query);
        const visible = categoryMatches && searchMatches;
        item.hidden = !visible;
        if (visible) visibleCount += 1;
      });

      if (status) {
        const itemLabel = visibleCount === 1 ? config.singularLabel : config.pluralLabel;
        status.textContent = `${visibleCount} ${itemLabel} shown`;
      }
      if (empty) empty.hidden = visibleCount !== 0;
      if (config.groupSelector) {
        document.querySelectorAll(config.groupSelector).forEach((group) => {
          group.hidden = !group.querySelector(config.itemSelector + ':not([hidden])');
        });
      }
    };

    filters.forEach((filter) => {
      filter.addEventListener('click', () => {
        activeCategory = filter.dataset[config.filterDataKey] || 'all';
        filters.forEach((button) => {
          const isActive = button === filter;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-pressed', String(isActive));
        });
        update();
      });
    });
    search.addEventListener('input', update);
  };

  setupContentFilters({
    itemSelector: '[data-news-item]',
    searchSelector: '[data-news-search]',
    statusSelector: '[data-news-status]',
    emptySelector: '[data-news-empty]',
    filterSelector: '[data-news-filter]',
    filterDataKey: 'newsFilter',
    singularLabel: 'story',
    pluralLabel: 'stories'
  });
  setupContentFilters({
    itemSelector: '[data-program-item]',
    searchSelector: '[data-program-search]',
    statusSelector: '[data-program-status]',
    emptySelector: '[data-program-empty]',
    filterSelector: '[data-program-filter]',
    filterDataKey: 'programFilter',
    singularLabel: 'show',
    pluralLabel: 'shows',
    groupSelector: '[data-program-group]'
  });

  const socialFeedItems = Array.from(document.querySelectorAll('[data-social-feed-item]'));
  const socialFeedSearch = document.querySelector('[data-social-feed-search]');
  const socialFeedStatus = document.querySelector('[data-social-feed-status]');
  const socialFeedEmpty = document.querySelector('[data-social-feed-empty]');
  const socialKindFilters = Array.from(document.querySelectorAll('[data-social-kind-filter]'));
  const socialPlatformFilters = Array.from(document.querySelectorAll('[data-social-platform-filter]'));
  if (socialFeedItems.length && socialFeedSearch) {
    let activeSocialKind = 'all';
    let activeSocialPlatform = 'all';
    const updateSocialFeed = () => {
      const query = socialFeedSearch.value.trim().toLocaleLowerCase();
      let visibleCount = 0;
      socialFeedItems.forEach((item) => {
        const kindMatches = activeSocialKind === 'all' || item.dataset.kind === activeSocialKind;
        const platformMatches = activeSocialPlatform === 'all' || item.dataset.platform === activeSocialPlatform;
        const searchMatches = !query || (item.dataset.search || '').includes(query);
        const visible = kindMatches && platformMatches && searchMatches;
        item.hidden = !visible;
        if (visible) visibleCount += 1;
      });
      if (socialFeedStatus) {
        socialFeedStatus.textContent = `${visibleCount} ${visibleCount === 1 ? 'item' : 'items'} shown`;
      }
      if (socialFeedEmpty) socialFeedEmpty.hidden = visibleCount > 0;
    };
    const connectSocialFilters = (filters, dataKey, setActive) => {
      filters.forEach((filter) => {
        filter.addEventListener('click', () => {
          setActive(filter.dataset[dataKey] || 'all');
          filters.forEach((button) => {
            const isActive = button === filter;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
          });
          updateSocialFeed();
        });
      });
    };
    connectSocialFilters(socialKindFilters, 'socialKindFilter', (kind) => { activeSocialKind = kind; });
    connectSocialFilters(socialPlatformFilters, 'socialPlatformFilter', (platform) => { activeSocialPlatform = platform; });
    socialFeedSearch.addEventListener('input', updateSocialFeed);
  }

  const onAirRows = Array.from(document.querySelectorAll('[data-on-air-row]'));
  if (onAirRows.length) {
    const weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const weekdayIndices = {
      Sunday: 0,
      Monday: 1,
      Tuesday: 2,
      Wednesday: 3,
      Thursday: 4,
      Friday: 5,
      Saturday: 6
    };
    const parseClockTime = (value) => {
      const match = value.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
      if (!match) return null;
      let hour = Number(match[1]) % 12;
      if (match[3].toUpperCase() === 'PM') hour += 12;
      return hour * 60 + Number(match[2]);
    };
    const parseSchedule = (schedule) => {
      const match = schedule.match(/^(Weekdays|Every Saturday|Every Sunday)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i);
      if (!match) return null;
      const start = parseClockTime(match[2]);
      const end = parseClockTime(match[3]);
      if (start === null || end === null || end <= start) return null;
      let days = [0, 1, 2, 3, 4, 5, 6];
      if (match[1].toLowerCase() === 'weekdays') days = [1, 2, 3, 4, 5];
      if (match[1].toLowerCase() === 'every saturday') days = [6];
      if (match[1].toLowerCase() === 'every sunday') days = [0];
      return {start, end, days, label: schedule};
    };
    const scheduledShows = onAirRows.map((row) => ({
      row,
      title: row.dataset.title || 'RCM programme',
      schedule: parseSchedule(row.dataset.schedule || '')
    }));
    const clock = document.querySelector('[data-nairobi-clock]');
    const currentTitle = document.querySelector('[data-current-show]');
    const currentSchedule = document.querySelector('[data-current-schedule]');
    const nextTitle = document.querySelector('[data-next-show]');
    const nextSchedule = document.querySelector('[data-next-schedule]');
    const scheduleTabs = Array.from(document.querySelectorAll('[data-schedule-filter]'));
    const getNairobiTime = () => {
      const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Africa/Nairobi',
        weekday: 'long',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23'
      }).formatToParts(new Date());
      const values = {};
      parts.forEach((part) => { values[part.type] = part.value; });
      return {day: weekdayIndices[values.weekday], weekday: values.weekday, minutes: Number(values.hour) * 60 + Number(values.minute)};
    };
    const formatMinutes = (minutes) => {
      const hour = Math.floor(minutes / 60) % 24;
      const minute = minutes % 60;
      return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour >= 12 ? 'PM' : 'AM'}`;
    };
    const updateOnAir = () => {
      const time = getNairobiTime();
      const now = new Date();
      if (clock) {
        clock.dateTime = now.toISOString();
        clock.textContent = new Intl.DateTimeFormat('en-KE', {
          timeZone: 'Africa/Nairobi',
          hour: 'numeric',
          minute: '2-digit'
        }).format(now);
      }

      scheduledShows.forEach((show) => {
        show.row.classList.remove('is-on-air');
        show.row.removeAttribute('aria-current');
      });
      const current = scheduledShows.find((show) => show.schedule && show.schedule.days.includes(time.day) && time.minutes >= show.schedule.start && time.minutes < show.schedule.end);
      if (current) {
        current.row.classList.add('is-on-air');
        current.row.setAttribute('aria-current', 'true');
        if (currentTitle) currentTitle.textContent = current.title;
        if (currentSchedule) currentSchedule.textContent = current.schedule.label;
      } else {
        if (currentTitle) currentTitle.textContent = 'Between scheduled shows';
        if (currentSchedule) currentSchedule.textContent = 'Tune in for music and station updates · East Africa Time';
      }

      let next = null;
      scheduledShows.forEach((show) => {
        if (!show.schedule) return;
        for (let dayOffset = 0; dayOffset <= 7; dayOffset += 1) {
          const candidateDay = (time.day + dayOffset) % 7;
          if (!show.schedule.days.includes(candidateDay)) continue;
          let minutesUntil = dayOffset * 1440 + show.schedule.start - time.minutes;
          if (minutesUntil <= 0) continue;
          if (!next || minutesUntil < next.minutesUntil) next = {show, minutesUntil, dayOffset};
        }
      });
      if (next) {
        const dayLabel = next.dayOffset === 0 ? 'Today' : next.dayOffset === 1 ? 'Tomorrow' : weekdayNames[(time.day + next.dayOffset) % 7];
        if (nextTitle) nextTitle.textContent = next.show.title;
        if (nextSchedule) nextSchedule.textContent = `${dayLabel} · ${formatMinutes(next.show.schedule.start)} · ${next.show.schedule.label}`;
      } else {
        if (nextTitle) nextTitle.textContent = 'Schedule to be announced';
        if (nextSchedule) nextSchedule.textContent = 'Check back for the next programme.';
      }
    };
    scheduleTabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        const category = tab.dataset.scheduleFilter;
        scheduleTabs.forEach((button) => {
          const active = button === tab;
          button.classList.toggle('is-active', active);
          button.setAttribute('aria-pressed', String(active));
        });
        onAirRows.forEach((row) => {
          row.hidden = category !== 'all' && row.dataset.category !== category;
        });
      });
    });
    updateOnAir();
    window.setInterval(updateOnAir, 60000);
  }

  const audio = document.getElementById('liveAudio');
  const play = document.getElementById('playLive');
  const mute = document.getElementById('muteLive');
  const status = document.getElementById('playerStatus');
  const message = document.getElementById('playerMessage');
  if (audio && play) {
    const setStatus = (text) => { if (status) status.textContent = text; };
    play.addEventListener('click', async () => {
      if (!audio.src || audio.src === window.location.href) {
        if (message) message.textContent = 'The live stream has not been configured yet. Add your official MP3/AAC stream URL in Admin → Station settings.';
        setStatus('Stream not configured');
        return;
      }
      if (audio.paused) {
        try {
          setStatus('Connecting…');
          await audio.play();
          play.textContent = 'Ⅱ';
          play.setAttribute('aria-label', 'Pause live radio');
          setStatus('You are listening live');
          if (message) message.textContent = 'Enjoy the broadcast. If playback fails, check the stream URL and hosting CORS / HTTPS settings.';
        } catch (err) {
          setStatus('Unable to play stream');
          if (message) message.textContent = 'Could not start the stream. Check that the URL is a direct, publicly accessible audio stream.';
        }
      } else {
        audio.pause();
        play.textContent = '▶';
        play.setAttribute('aria-label', 'Play live radio');
        setStatus('Paused');
      }
    });
    audio.addEventListener('error', () => {
      setStatus('Stream unavailable');
      if (message) message.textContent = 'The stream could not be loaded. Please verify the stream URL with your broadcaster or streaming provider.';
      play.textContent = '▶';
    });
    audio.addEventListener('playing', () => setStatus('You are listening live'));
    audio.addEventListener('pause', () => {
      if (!audio.ended && !audio.error) {
        play.textContent = '▶';
        play.setAttribute('aria-label', 'Play live radio');
      }
    });
    if (mute) mute.addEventListener('click', () => {
      audio.muted = !audio.muted;
      mute.textContent = audio.muted ? '◖×' : '◖))';
      mute.setAttribute('aria-label', audio.muted ? 'Unmute audio' : 'Mute audio');
    });
  }
})();