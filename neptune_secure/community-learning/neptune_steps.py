"""Plain step plans for helper jobs, so the window and the website say what is running now and what comes next.

The helper does not choose work. It runs whatever Neptune queued and describes it with these plans.
"""

# (title, [(step name, message prefixes that mean this step is running)])
PLANS = {
    'prepare': ('Prepare frames for Robot labels', [
        ('Download the match video', ('Downloading',)),
        ('Find bumpers in each frame', ('Finding bumpers',)),
        ('Send the boxes to Robot labels', ('Prepared',)),
    ]),
    'train': ('Train the robot detector', [
        ('Download robot labels', ('Downloading robot labels',)),
        ('Prepare training frames', ('Preparing', 'Downloading')),
        ('Train', ('Training robot detector',)),
        ('Compare with the detector in use', ('Comparing',)),
        ('Make the review video', ('Making the review video',)),
        ('Share it if better', ('Sharing', 'Adopted', 'Kept')),
    ]),
    'train_field': ('Train the field detector', [
        ('Download scoring areas', ('Downloading scoring areas',)),
        ('Prepare field frames', ('Preparing field frames', 'Downloading')),
        ('Train', ('Training field detector',)),
        ('Compare with the detector in use', ('Comparing',)),
        ('Make the review video', ('Making the review video',)),
        ('Share it if better', ('Sharing', 'Adopted', 'Kept')),
    ]),
    'actions': ('Train action models & scoring', [
        ('Collect tags and imports', ('Syncing', 'Preparing training', 'Checking saved')),
        ('Prepare each match: video, numbers, robot tracking', ('[', 'Verifying', 'Refreshing', 'Tracking', 'Extracting', 'Preparing', 'Reusing', 'Rechecked')),
        ('Fit action and scoring models', ('Fitting',)),
        ('Check on held-out matches and save', ('Evaluating', 'Recovering', 'Finished')),
    ]),
}


# Rough share of a job's time each step takes, for the % complete (measured on the owner's Mac, Oct 2026).
WEIGHTS = {'prepare': [.15, .8, .05], 'train': [.02, .08, .82, .03, .03, .02], 'train_field': [.02, .1, .8, .03, .03, .02],
           'actions': [.02, .9, .06, .02]}


def within(message, current):
    """How far through its step a message says the job is (0-1), or None when the message does not say.
    `current` carries the match being prepared between messages."""
    import re
    m = re.search(r'pass (\d+) of up to (\d+)(?: · batch (\d+) of (\d+))?', message)
    if m:
        p, e = int(m[1]), int(m[2]); frac = (int(m[3])/int(m[4])) if m[3] else 0.
        return min(1., ((p-1)+frac)/e)
    m = re.search(r'frame (\d+) of (\d+)', message)
    if m:
        return int(m[1])/int(m[2])
    m = re.search(r'\[(\d+)/(\d+)\]', message)
    if m:
        current.update(match=int(m[1]), matches=int(m[2]), part=0.)
    m = re.search(r'(\d+) of (\d+) matches prepared', message)
    if m:
        current.update(match=int(m[1])+1, matches=int(m[2]), part=0.)
    m = re.search(r'frames \d+\D(\d+) of (\d+)', message)
    if m and current.get('matches'):
        current['part'] = .3*int(m[1])/max(1, int(m[2]))      # reading numbers and the scoreboard comes first
    m = re.search(r'(\d+) of (\d+) video seconds', message)
    if m and current.get('matches'):
        current['part'] = .3+.4*int(m[1])/max(1, int(m[2]))      # then tracking
    if 'Extracting robot examples' in message and current.get('matches'):
        current['part'] = max(current.get('part', 0.), .7)
    if current.get('matches'):
        return min(1., (current['match']-1+current.get('part', 0.))/current['matches'])
    return None


def left(seconds):
    if seconds < 90:
        return 'about a minute'
    if seconds < 3600:
        return 'about %d min' % round(seconds/60)
    return 'about %.1f h' % (seconds/3600)


class Steps:
    """Turns raw progress messages into 'step N of M / Now / Next / Progress'. Steps only move forward."""

    def __init__(self, kind):
        import time
        self.title, self.steps = PLANS[kind]
        self.weights = WEIGHTS.get(kind, [1/len(self.steps)]*len(self.steps))
        self.at = 0; self.fraction = 0.; self.current = {}; self.started = time.monotonic(); self.best = 0.

    def percent(self):
        """Overall % complete (never goes backwards) and seconds left, or None for the time until it can be judged."""
        import time
        done = sum(self.weights[:self.at])+self.weights[self.at]*self.fraction
        self.best = max(self.best, min(.99, done))
        elapsed = time.monotonic()-self.started
        eta = elapsed/self.best*(1-self.best) if self.best >= .03 and elapsed >= 45 else None
        return round(100*self.best), eta

    def describe(self, message):
        message = str(message)
        for i in range(self.at, len(self.steps)):
            if message.startswith(self.steps[i][1]):
                self.at = i
                break
        before = self.at
        name = self.steps[self.at][0]
        f = within(message, self.current)
        if self.at != getattr(self, '_fraction_step', None):
            self.fraction = 0.; self._fraction_step = self.at
        if f is not None:
            self.fraction = max(self.fraction, f)
        upcoming = [s[0] for s in self.steps[self.at+1:]]
        lines = ['%s · step %d of %d: %s' % (self.title, self.at+1, len(self.steps), name), 'Now: '+message]
        lines.append('Next: '+(' → '.join(upcoming) if upcoming else 'done'))
        pct, eta = self.percent()
        # The site and the Mac app read this line for the progress bar.
        lines.append('Progress: %d%%' % pct + (' · %s left' % left(eta) if eta else ' · estimating time left'))
        return '\n'.join(lines)
