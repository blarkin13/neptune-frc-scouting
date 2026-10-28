"""Shared desktop presentation for the Windows and Linux training helpers.

The native Mac app uses the same palette and layout, implemented in AppKit.
Icons: Lucide (ISC licence, neptune-icons-LICENSE.txt), pre-rendered into neptune-icons.png.
"""
from pathlib import Path
import tkinter as tk
from tkinter import font as tkfont
from tkinter import ttk

HERE = Path(__file__).resolve().parent

INK = '#12233d'
PANEL = '#172e4c'
PANEL_ALT = '#1f3a5c'
LINE = '#385c80'
LOG = '#0d1a2e'
SIDEBAR = '#0a1628'
WARN = '#fac76b'
TEXT = '#f1f7fc'
MUTED = '#a8bdcf'
ACCENT = '#53d4ed'
ACCENT_TEXT = '#06202a'
GOOD = '#85ddc3'
COLLAPSED_SIZE = '600x530'
EXPANDED_SIZE = '600x718'


def apply(root):
    root.configure(background=INK)
    style = ttk.Style(root)
    style.theme_use('clam')
    # A thin scrollbar with no arrows, like the Mac's overlay scroller.
    style.layout('Thin.Vertical.TScrollbar',[('Vertical.Scrollbar.trough',{'children':[('Vertical.Scrollbar.thumb',{'expand':'1','sticky':'nswe'})],'sticky':'ns'})])
    style.configure('Thin.Vertical.TScrollbar',troughcolor=INK,background='#5a7fa6',bordercolor=INK,lightcolor='#5a7fa6',darkcolor='#5a7fa6',gripcount=0,arrowsize=9,width=9)
    style.map('Thin.Vertical.TScrollbar',background=[('active',MUTED)])
    # Flat, borderless meter bars.
    style.configure('Meter.Horizontal.TProgressbar',background='#8fb6de',troughcolor='#0b1a2e',bordercolor='#0b1a2e',lightcolor='#8fb6de',darkcolor='#8fb6de',borderwidth=0,thickness=6)
    style.configure('Neptune.TFrame', background=INK)
    style.configure('Card.TFrame', background=PANEL)
    style.configure('Neptune.TLabel', background=INK, foreground=TEXT, font=('Arial', 11))
    style.configure('Muted.TLabel', background=INK, foreground=MUTED, font=('Arial', 10))
    style.configure('Card.TLabel', background=PANEL, foreground=TEXT, font=('Arial', 12))
    style.configure('CardMuted.TLabel', background=PANEL, foreground=MUTED, font=('Arial', 10))
    style.configure('Section.TLabel', background=INK, foreground=MUTED, font=('Arial', 10, 'bold'))
    style.configure('Neptune.TButton', background=ACCENT, foreground=ACCENT_TEXT,
                    borderwidth=0, padding=(12, 7), font=('Arial', 10, 'bold'))
    style.map('Neptune.TButton', background=[('active', '#86e7f6'), ('disabled', PANEL_ALT)],
              foreground=[('disabled', MUTED)])
    style.configure('Subtle.TButton', background=PANEL_ALT, foreground=TEXT,
                    borderwidth=1, bordercolor=LINE, padding=(10, 6), font=('Arial', 10))
    style.map('Subtle.TButton', background=[('active', '#1d3d56'), ('disabled', PANEL)],
              foreground=[('disabled', MUTED)])
    style.configure('Neptune.TCombobox', fieldbackground=PANEL_ALT, background=PANEL_ALT, bordercolor=LINE,
                    lightcolor=PANEL_ALT, darkcolor=PANEL_ALT, foreground=TEXT, arrowcolor=ACCENT, padding=5, relief='flat')
    style.map('Neptune.TCombobox', bordercolor=[('focus', ACCENT)], lightcolor=[('focus', PANEL_ALT)], background=[('active', PANEL_ALT)])
    root.option_add('*TCombobox*Listbox.background', PANEL_ALT);root.option_add('*TCombobox*Listbox.foreground', TEXT)
    root.option_add('*TCombobox*Listbox.selectBackground', ACCENT);root.option_add('*TCombobox*Listbox.selectForeground', ACCENT_TEXT)
    style.map('Neptune.TCombobox', fieldbackground=[('readonly', PANEL_ALT)],
              foreground=[('readonly', TEXT)])
    style.configure('Neptune.Horizontal.TProgressbar', background=ACCENT, troughcolor=PANEL_ALT,
                    borderwidth=0)


ICONS = ('overview','robots','field','actions','computers','activity','review','images','laptop','server','refresh','play','open','download','drive','cpu')
ICON_COLORS = ('text','accent','muted','ink')
_icon_cache = {}


def icon(name,color='text'):
    """An 18-px icon from the shipped strip (columns: ICONS, rows: ICON_COLORS); None if it can't load."""
    key=(name,color)
    if key in _icon_cache:return _icon_cache[key]
    try:
        if 'strip' not in _icon_cache:_icon_cache['strip']=tk.PhotoImage(file=str(HERE/'neptune-icons.png'))
        strip=_icon_cache['strip'];x,y=18*ICONS.index(name),18*ICON_COLORS.index(color)
        image=tk.PhotoImage(width=18,height=18);image.tk.call(image,'copy',strip,'-from',x,y,x+18,y+18,'-to',0,0)
    except (tk.TclError,ValueError):image=None
    _icon_cache[key]=image;return image


def _blend(a,b,t):
    a=[int(a[i:i+2],16) for i in (1,3,5)];b=[int(b[i:i+2],16) for i in (1,3,5)]
    return '#%02x%02x%02x'%tuple(round(x+(y-x)*t) for x,y in zip(a,b))


class Button(tk.Canvas):
    """A rounded, flat button (primary: accent fill; secondary: panel fill with a fine border) with an optional icon.
    Accepts config(text=, state=, command=) like a ttk button."""
    STYLES={'primary':(ACCENT,ACCENT_TEXT,None,'ink'),'secondary':(PANEL_ALT,TEXT,LINE,'text'),'ghost':(None,ACCENT,None,'accent')}
    def __init__(self,parent,text='',command=None,*,kind='secondary',icon_name=None,small=False,state='normal'):
        try:bg=parent.cget('bg')
        except tk.TclError:bg=INK
        super().__init__(parent,bg=bg,highlightthickness=0,borderwidth=0,cursor='hand2')
        self._text,self._command,self._kind,self._icon,self._state,self._hover=text,command,kind,icon_name,state,False
        self._font=tkfont.Font(family='Arial',size=9 if small else 10,weight='bold' if kind=='primary' else 'normal')
        self._h=26 if small else 32;self._images=[]
        self.bind('<Enter>',lambda e:self._set_hover(True));self.bind('<Leave>',lambda e:self._set_hover(False))
        self.bind('<ButtonRelease-1>',self._click);self._draw()
    def _set_hover(self,value):self._hover=value;self._draw()
    def _click(self,event):
        if self._state!='disabled' and self._command and 0<=event.x<=self.winfo_width() and 0<=event.y<=self.winfo_height():self._command()
    def configure(self,cnf=None,**kw):
        changed=False
        for key in ('text','state','command'):
            if key in kw:setattr(self,'_'+key,kw.pop(key));changed=True
        result=super().configure(cnf,**kw) if (cnf or kw) else None
        if changed:self._draw()
        return result
    config=configure
    def cget(self,key):return getattr(self,'_'+key) if key in ('text','state','command') else super().cget(key)
    def _draw(self):
        fill,fg,border,icon_color=self.STYLES[self._kind]
        disabled=self._state=='disabled'
        if disabled:fill,fg,border,icon_color=(PANEL if fill else None),MUTED,(LINE if border else None),'muted'
        elif self._hover and fill:fill=_blend(fill,'#ffffff',.14)
        elif self._hover:fg=TEXT
        picture=icon(self._icon,icon_color) if self._icon else None
        pad=12 if self._h>26 else 9;w=self._font.measure(self._text)+2*pad+(22 if picture else 0);h=self._h
        self.delete('all');self.configure(width=w,height=h);self._images=[]
        if fill:self._rounded(w,h,fill,border)
        x=pad
        if picture:self.create_image(x,h//2,image=picture,anchor='w');x+=22
        self.create_text(x,h//2,text=self._text,fill=fg,font=self._font,anchor='w')
    def _rounded(self,w,h,fill,border):
        # Smooth corners with Pillow (every helper's Python environment has it); a smoothed polygon otherwise.
        try:
            from PIL import Image,ImageDraw,ImageTk
            k=4;big=Image.new('RGBA',(w*k,h*k),(0,0,0,0));d=ImageDraw.Draw(big)
            d.rounded_rectangle((0,0,w*k-1,h*k-1),radius=8*k,fill=fill,outline=border,width=k if border else 0)
            image=ImageTk.PhotoImage(big.resize((w,h),Image.LANCZOS));self._images.append(image);self.create_image(0,0,image=image,anchor='nw')
        except Exception:
            r=8;pts=[r,0,w-r,0,w,0,w,r,w,h-r,w,h,w-r,h,r,h,0,h,0,h-r,0,r,0,0]
            self.create_polygon(pts,smooth=True,fill=fill,outline=border or fill)


class Switch(tk.Canvas):
    """An on/off switch in the app's colours (accent track when on), with its label; click either to toggle.
    command(value) runs after each change."""
    def __init__(self,parent,text='',value=False,command=None):
        try:bg=parent.cget('bg')
        except tk.TclError:bg=PANEL
        super().__init__(parent,bg=bg,highlightthickness=0,borderwidth=0,cursor='hand2')
        self.value,self.command,self._bg,self._images=bool(value),command,bg,[]
        self._font=tkfont.Font(family='Arial',size=9);self._text=text
        self.bind('<ButtonRelease-1>',self._click);self._draw()
    def get(self):return self.value
    def _click(self,event):
        self.value=not self.value;self._draw()
        if self.command:self.command(self.value)
    def _draw(self):
        tw,th=30,17;w=tw+(8+self._font.measure(self._text) if self._text else 0);h=max(th,20)
        self.delete('all');self.configure(width=w,height=h);self._images=[]
        track=ACCENT if self.value else '#33506f';y=(h-th)//2;knob_x=tw-th+2 if self.value else 2
        try:
            from PIL import Image,ImageDraw,ImageTk
            k=4;img=Image.new('RGBA',(tw*k,th*k),(0,0,0,0));d=ImageDraw.Draw(img)
            d.rounded_rectangle((0,0,tw*k-1,th*k-1),radius=th*k//2,fill=track)
            d.ellipse((knob_x*k,2*k,(knob_x+th-4)*k,(th-2)*k),fill='#ffffff')
            image=ImageTk.PhotoImage(img.resize((tw,th),Image.LANCZOS));self._images.append(image);self.create_image(0,y,image=image,anchor='nw')
        except Exception:
            self.create_line(th/2,y+th/2,tw-th/2,y+th/2,width=th,capstyle='round',fill=track)
            self.create_oval(knob_x,y+2,knob_x+th-4,y+th-2,fill='#ffffff',outline='')
        if self._text:self.create_text(tw+8,h//2,text=self._text,fill=TEXT,font=self._font,anchor='w')


def header(parent, *, logo=None, subtitle='LOCAL TRAINING COMPUTER', version=''):
    """Compact brand row; the same information order is used by the Mac app."""
    row = tk.Frame(parent, bg=INK)
    row.pack(fill='x', pady=(0, 12))
    logo_path = Path(logo) if logo else None
    if logo_path and logo_path.is_file():
        try:
            picture = tk.PhotoImage(file=str(logo_path))
            picture = picture.subsample(max(1, (picture.width() + 51) // 52))
            mark = tk.Label(row, image=picture, bg=INK, borderwidth=0)
            mark.image = picture
            mark.pack(side='left', padx=(0, 13))
        except tk.TclError:
            logo_path = None
    if not logo_path or not logo_path.is_file():
        tk.Label(row, text='N', font=('Arial', 26, 'bold'), fg=ACCENT, bg=PANEL,
                 width=2).pack(side='left', padx=(0, 13))
    copy = tk.Frame(row, bg=INK)
    copy.pack(side='left', fill='x', expand=True)
    tk.Label(copy, text='NEPTUNE  /  TRAINING', font=('Arial', 9, 'bold'),
             fg=ACCENT, bg=INK, anchor='w').pack(fill='x')
    tk.Label(copy, text='Training Helper', font=('Arial', 20, 'bold'),
             fg=TEXT, bg=INK, anchor='w').pack(fill='x', pady=(2, 1))
    tk.Label(copy, text=version or subtitle, font=('Arial', 9), fg=MUTED, bg=INK,
             anchor='w').pack(fill='x')
    return row


def card(parent, *, padding=12):
    outer = tk.Frame(parent, bg=LINE, padx=1, pady=1)
    outer.pack(fill='x', pady=(0, 10))
    inner = tk.Frame(outer, bg=PANEL, padx=padding, pady=padding)
    inner.pack(fill='both', expand=True)
    return inner


def status(parent, value, *, detail=None, eyebrow='CONNECTION'):
    inner = card(parent)
    tk.Label(inner, text=eyebrow, font=('Arial', 9, 'bold'), fg=ACCENT,
             bg=PANEL, anchor='w').pack(fill='x')
    # Room for four lines whatever the message, so the window never changes size with the text.
    tk.Label(inner, textvariable=value, font=('Arial', 11), fg=TEXT, height=4,
             bg=PANEL, anchor='nw', justify='left', wraplength=500).pack(fill='x', pady=(5, 2))
    if detail is not None:
        tk.Label(inner, text=detail, font=('Arial', 10), fg=MUTED, bg=PANEL,
                 anchor='w', justify='left', wraplength=500).pack(fill='x')
    return inner


def activity(parent):
    ttk.Label(parent, text='ACTIVITY', style='Section.TLabel').pack(anchor='w', pady=(5, 7))
    border = tk.Frame(parent, bg=LINE, padx=1, pady=1)
    border.pack(fill='both', expand=True)
    text = tk.Text(border, height=7, wrap='word', state='disabled', borderwidth=0,
                   highlightthickness=0, bg=LOG, fg=TEXT, insertbackground=TEXT,
                   font=('Menlo', 10), padx=12, pady=10)
    scroll = ttk.Scrollbar(border, orient='vertical', command=text.yview)
    text.configure(yscrollcommand=scroll.set)
    scroll.pack(side='right', fill='y')
    text.pack(side='left', fill='both', expand=True)
    return text

FORM_ICON={'laptop':'laptop','desktop':'computers','server':'server'}


class Meter(tk.Canvas):
    """A thin rounded usage bar (0–1), like the Mac app's meters."""
    def __init__(self,parent,fraction=None,*,height=6):
        super().__init__(parent,height=height,bg=parent.cget('bg'),highlightthickness=0,borderwidth=0)
        self.fraction=fraction;self.bind('<Configure>',lambda e:self._draw())
    def set(self,fraction):self.fraction=fraction;self._draw()
    def _draw(self):
        w,h=max(1,self.winfo_width()),int(self.cget('height'));self.delete('all')
        self.create_line(h/2,h/2,w-h/2,h/2,width=h,capstyle='round',fill='#0b1a2e')
        if self.fraction:self.create_line(h/2,h/2,h/2+max(0,(w-h)*min(1,self.fraction)),h/2,width=h,capstyle='round',fill='#8fb6de')


class ComputerCard(tk.Frame):
    """The desktop equivalent of Neptune's connected-computer card."""
    def __init__(self,parent,*,name,logo=None,form=None):
        super().__init__(parent,bg=LINE,padx=1,pady=1)
        self.pack(fill='x',pady=(0,8));self.connected=False;self.working=False
        box=tk.Frame(self,bg=PANEL,padx=14,pady=12);box.pack(fill='both',expand=True)
        head=tk.Frame(box,bg=PANEL);head.pack(fill='x')
        # Everything below the name row collapses with the caret; callers add their rows to .inner.
        inner=self.inner=tk.Frame(box,bg=PANEL);caret(head,'this-computer',inner).pack(side='left')
        # This computer's kind (laptop, desktop or server) in place of a logo.
        picture=icon(FORM_ICON.get(form,'computers'),'accent')
        if picture:tk.Label(head,image=picture,bg=PANEL_ALT,padx=7,pady=7).pack(side='left',padx=(0,10))
        copy=tk.Frame(head,bg=PANEL);copy.pack(side='left',fill='x',expand=True)
        tk.Label(copy,text=name,font=('Arial',13,'bold'),fg=TEXT,bg=PANEL,anchor='w').pack(fill='x')
        self.detail=tk.StringVar(value='Reading hardware…');tk.Label(copy,textvariable=self.detail,font=('Arial',9),fg=MUTED,bg=PANEL,anchor='w',wraplength=340,justify='left').pack(fill='x')
        self.badge=tk.StringVar(value='Connecting');tk.Label(head,textvariable=self.badge,font=('Arial',10,'bold'),fg=TEXT,bg=PANEL_ALT,padx=9,pady=5).pack(side='right')
        grid=tk.Frame(inner,bg=PANEL);grid.pack(fill='x');grid.columnconfigure(0,weight=1);grid.columnconfigure(1,weight=1)
        self.meters={}
        for i,label in enumerate(('CPU','GPU','RAM','GPU memory')):
            cell=tk.Frame(grid,bg=PANEL);cell.grid(row=i//2,column=i%2,sticky='ew',padx=(0,12) if i%2==0 else (0,0),pady=(0,8))
            top=tk.Frame(cell,bg=PANEL);top.pack(fill='x')
            tk.Label(top,text=label,font=('Arial',10),fg=MUTED,bg=PANEL).pack(side='left')
            value=tk.StringVar(value='—');tk.Label(top,textvariable=value,font=('Arial',10),fg=TEXT,bg=PANEL).pack(side='right')
            bar=Meter(cell);bar.pack(fill='x',pady=(6,0))
            self.meters[label]=(value,bar)
        self.note=tk.StringVar(value='No assigned job');tk.Label(inner,textvariable=self.note,font=('Arial',9),fg=MUTED,bg=PANEL,anchor='w').pack(fill='x')
    def set_working(self,value):
        self.working=bool(value);self.badge.set('Working' if value else 'Ready to train' if self.connected else 'Connecting');self.note.set('Job in progress' if value else 'Ready to train · start a run from a model page or from Neptune')
    def set_connected(self,value):
        self.connected=bool(value);self.badge.set('Working' if self.working else 'Ready to train' if value else 'Offline')
    def update_readings(self,payload):
        stats=payload.get('stats') or {};gpu=stats.get('gpu') or {}
        def put(label,pct,value):
            text,bar=self.meters[label];text.set(value);bar.set(max(0,min(100,pct))/100 if pct is not None else None)
        for label,data,key in [('CPU',stats,'cpu'),('GPU',gpu,'util')]:
            value=data.get(key);put(label,value,f'{value:g}%' if isinstance(value,(int,float)) else '—')
        for label,data,used,total in [('RAM',stats,'ram_used_mb','ram_total_mb'),('GPU memory',gpu,'mem_used_mb','mem_total_mb')]:
            u,t=data.get(used),data.get(total)
            value='—' if u is None else f'{u/1024:.1f} / {t/1024:.1f} GB' if t else f'{u/1024:.1f} GB'
            put(label,100*u/t if u is not None and t else None,value)
        self.detail.set((payload.get('platform') or 'Computer')+' · '+(gpu.get('name') or 'GPU unavailable'))


class BubblePage(tk.Canvas):
    """Scrolling page of cards over the page's artwork; cards stretch to the window width.
    A new page is built hidden and swapped in at once (no blink); the scrollbar shows only when the cards overflow."""
    def __init__(self,parent,*,pad=22,gap=12):
        super().__init__(parent,bg=INK,highlightthickness=0,borderwidth=0)
        self.pad,self.gap,self.items,self.old=pad,gap,[],[]
        self.art_path=None;self.art_source=None;self.art_image=None;self.art_size=None;self.art_item=None;self._art_job=None
        self.scroll=ttk.Scrollbar(parent,orient='vertical',command=self.yview,style='Thin.Vertical.TScrollbar');self.configure(yscrollcommand=self._scrolled)
        self.pack(side='left',fill='both',expand=True)
        self.bind('<Configure>',lambda event:(self.relayout(),self._art_soon()))
        def wheel(amount):
            if self.scroll.winfo_ismapped():self.yview_scroll(amount,'units')
        self.bind_all('<MouseWheel>',lambda event:wheel(int(-event.delta/(1 if abs(event.delta)<120 else 120))))
        self.bind_all('<Button-4>',lambda event:wheel(-3));self.bind_all('<Button-5>',lambda event:wheel(3))
    def _scrolled(self,first,last):
        self.scroll.set(first,last)
        if self.art_item:self.coords(self.art_item,0,self.canvasy(0))
    def set_art(self,path):
        """Artwork behind this page's cards, scaled to cover and dimmed so cards stay readable (needs Pillow)."""
        if path==self.art_path:return
        self.art_path=path;self.art_source=None;self.art_size=None
        try:
            from PIL import Image
            if path and Path(path).is_file():self.art_source=Image.open(path).convert('RGB')
        except Exception:self.art_source=None
        self._draw_art()
    def _art_soon(self):
        if self._art_job:self.after_cancel(self._art_job)
        self._art_job=self.after(120,self._draw_art)
    def _draw_art(self):
        self._art_job=None;w,h=self.winfo_width(),self.winfo_height()
        if not self.art_source or w<50 or h<50:
            if self.art_item:self.delete(self.art_item);self.art_item=None
            return
        if self.art_size==(w,h) and self.art_item:return
        from PIL import Image,ImageTk
        src=self.art_source;scale=max(w/src.width,h/src.height)
        image=src.resize((max(1,round(src.width*scale)),max(1,round(src.height*scale))),Image.LANCZOS)
        left,top=(image.width-w)//2,(image.height-h)//2;image=image.crop((left,top,left+w,top+h))
        image=Image.blend(image,Image.new('RGB',image.size,INK),.45)
        self.art_image=ImageTk.PhotoImage(image);self.art_size=(w,h)
        if self.art_item:self.itemconfigure(self.art_item,image=self.art_image)
        else:self.art_item=self.create_image(0,self.canvasy(0),image=self.art_image,anchor='nw');self.tag_lower(self.art_item)
    def add(self,widget):
        """Place a child of this canvas as the next card (hidden until the page is swapped in); returns the widget."""
        # A card that stays across pages (kept) stays visible; new cards wait for the swap.
        self.items.append((widget,self.create_window(0,0,window=widget,anchor='nw',state='normal' if widget in getattr(self,'_kept',()) else 'hidden')))
        if not getattr(widget,'_relayout_bound',False):
            widget.bind('<Configure>',lambda event:self.after_idle(self.relayout),add='+');widget._relayout_bound=True
        self.after_idle(self.relayout);return widget
    def show_only(self,keep,*,top=True):
        """Start building a new page. The current cards stay on screen until the new ones are laid out,
        then are destroyed (all but `keep`, which are re-added). top=False keeps the scroll position."""
        self.finish()
        self._top=top;self.old=[(w,i) for w,i in self.items];self.items=[];self._kept=set(keep)
        for widget,item in self.old:
            if widget in keep:self.delete(item)
        self.old=[(w,i) for w,i in self.old if w not in keep];self.after_idle(self.finish)
    def finish(self):
        """Swap the new page in: lay it out, show it, then remove the old cards."""
        if not self.old and not any(self.itemcget(i,'state')=='hidden' for _,i in self.items):return
        self.update_idletasks();self.relayout()
        for _,item in self.items:self.itemconfigure(item,state='normal')
        for widget,item in self.old:self.delete(item);widget.destroy()
        self.old=[]
        if getattr(self,'_top',False):self.yview_moveto(0);self._top=False
    def clear(self,keep=()):
        for widget,item in list(self.items):
            if widget not in keep:self.delete(item);widget.destroy();self.items.remove((widget,item))
    def move_to_end(self,widgets):
        for widget in widgets:
            entry=next((x for x in self.items if x[0] is widget),None)
            if entry:self.items.remove(entry);self.items.append(entry)
        self.after_idle(self.relayout)
    def relayout(self):
        width=max(200,self.winfo_width()-2*self.pad);y=self.pad
        for widget,item in self.items:
            if not widget.winfo_exists():continue
            self.coords(item,self.pad,y);self.itemconfigure(item,width=width)
            y+=widget.winfo_reqheight()+self.gap
        height=self.winfo_height();self.configure(scrollregion=(0,0,self.winfo_width(),max(y+self.pad,height)))
        if y+self.pad>height+2:
            if not self.scroll.winfo_ismapped():self.scroll.pack(side='right',fill='y',before=self)
        elif self.scroll.winfo_ismapped():self.scroll.pack_forget();self.yview_moveto(0)
COLLAPSED=set();_state_file=None


def load_state(path):
    """Remember which sections are collapsed between runs (a small JSON file in the helper's folder)."""
    global _state_file
    import json
    _state_file=Path(path)
    try:COLLAPSED.update(json.loads(_state_file.read_text()).get('collapsed') or [])
    except (OSError,ValueError,AttributeError):pass


def _save_state():
    import json
    if _state_file:
        try:_state_file.write_text(json.dumps({'collapsed':sorted(COLLAPSED)}))
        except OSError:pass


def caret(parent,key,body,*,bg=PANEL,pack=lambda b:b.pack(fill='x',pady=(8,0))):
    """A ▾/▸ toggle that shows or hides `body`; returns the caret label (clicking any bound label toggles too)."""
    label=tk.Label(parent,text='▸' if key in COLLAPSED else '▾',font=('Arial',12),fg=MUTED,bg=bg,cursor='hand2',width=2,anchor='w')
    def toggle(event=None):
        if key in COLLAPSED:COLLAPSED.discard(key);pack(body);label.config(text='▾')
        else:COLLAPSED.add(key);body.pack_forget();label.config(text='▸')
        _save_state()
    label.toggle=toggle;label.bind('<Button-1>',toggle)
    if key not in COLLAPSED:pack(body)
    return label


def page_card(page,title,*,key=None,icon_name=None):
    """A bordered card placed on a BubblePage; returns (outer, body). A titled card collapses with a caret;
    outer.head is its title row, for controls on the right."""
    outer=tk.Frame(page,bg=LINE,padx=1,pady=1);outer.head=None
    inner=tk.Frame(outer,bg=PANEL,padx=14,pady=12);inner.pack(fill='both',expand=True)
    if not title:page.add(outer);return outer,inner
    head=tk.Frame(inner,bg=PANEL);head.pack(fill='x');outer.head=head;body=tk.Frame(inner,bg=PANEL)
    toggle=caret(head,key or title,body);toggle.pack(side='left')
    picture=icon(icon_name,'accent') if icon_name else None
    if picture:tk.Label(head,image=picture,bg=PANEL_ALT,padx=5,pady=5).pack(side='left',padx=(0,8))
    name=tk.Label(head,text=title,font=('Arial',12,'bold'),fg=TEXT,bg=PANEL,anchor='w',cursor='hand2');name.pack(side='left')
    name.bind('<Button-1>',toggle.toggle)
    page.add(outer);return outer,body


def _meter(parent,label,fraction,text):
    cell=tk.Frame(parent,bg=PANEL);top=tk.Frame(cell,bg=PANEL);top.pack(fill='x')
    tk.Label(top,text=label,font=('Arial',10),fg=MUTED,bg=PANEL).pack(side='left')
    tk.Label(top,text=text,font=('Arial',10),fg=TEXT,bg=PANEL).pack(side='right')
    Meter(cell,fraction).pack(fill='x',pady=(6,0))
    return cell


class Shell:
    """Sidebar of pages beside a header and a scrolling page of cards, the same layout as the Mac app."""
    PAGES=[('overview','Overview'),('robots','Robots'),('field','Field'),('actions','Actions'),('computers','Computers'),('activity','Activity')]
    def __init__(self,root,*,logo=None,on_page=lambda key:None):
        self.on_page=on_page;self.nav={}
        side=tk.Frame(root,bg=SIDEBAR,width=210,padx=12,pady=16);side.pack(side='left',fill='y');side.pack_propagate(False)
        tk.Frame(root,bg=LINE,width=1).pack(side='left',fill='y')
        # The wide NEPTUNE wordmark across the sidebar.
        brand=tk.Frame(side,bg=SIDEBAR);brand.pack(fill='x',pady=(0,18))
        if logo and Path(logo).is_file():
            try:
                picture=tk.PhotoImage(file=str(logo));picture=picture.subsample(max(1,(picture.width()+185)//186))
                mark=tk.Label(brand,image=picture,bg=SIDEBAR);mark.image=picture;mark.pack(anchor='w')
            except tk.TclError:tk.Label(brand,text='NEPTUNE',font=('Arial',18,'bold'),fg=TEXT,bg=SIDEBAR,anchor='w').pack(fill='x')
        tk.Label(brand,text='HIPPOCAMPUS',font=('Arial',13,'bold'),fg=TEXT,bg=SIDEBAR,anchor='w').pack(fill='x',pady=(6,0))
        tk.Label(brand,text='Model Training Helper',font=('Arial',9),fg=MUTED,bg=SIDEBAR,anchor='w').pack(fill='x')
        def section(title):tk.Label(side,text=title.upper(),font=('Arial',8,'bold'),fg=MUTED,bg=SIDEBAR,anchor='w').pack(fill='x',pady=(14,4))
        for i,(key,title) in enumerate(self.PAGES):
            if i==1:section('Models')
            if i==4:section('Helpers')
            item=tk.Label(side,text='  '+title,image=icon(key),compound='left',font=('Arial',11),fg=TEXT,bg=SIDEBAR,anchor='w',padx=10,pady=6,cursor='hand2')
            item.pack(fill='x',pady=1);item.bind('<Button-1>',lambda event,k=key:self.on_page(k));self.nav[key]=item
        section('Tools')
        self.tools=tk.Frame(side,bg=SIDEBAR);self.tools.pack(fill='x')
        footer=tk.Frame(side,bg='#1a3150',padx=1,pady=1);footer.pack(side='bottom',fill='x')
        self.footer=tk.Frame(footer,bg=PANEL,padx=10,pady=10);self.footer.pack(fill='both')
        main=tk.Frame(root,bg=INK);main.pack(side='left',fill='both',expand=True)
        head=tk.Frame(main,bg=INK,padx=22,pady=14);head.pack(fill='x')
        self.title=tk.StringVar(value='Overview');self.status=tk.StringVar(value='Connecting to Neptune…')
        words=tk.Frame(head,bg=INK);words.pack(side='left',fill='x',expand=True)
        tk.Label(words,textvariable=self.title,font=('Arial',19,'bold'),fg=TEXT,bg=INK,anchor='w').pack(fill='x')
        tk.Label(words,textvariable=self.status,font=('Arial',10),fg=MUTED,bg=INK,anchor='w').pack(fill='x')
        self.right=tk.Frame(head,bg=INK);self.right.pack(side='right')
        self.actions=tk.Frame(head,bg=INK);self.actions.pack(side='right',padx=(0,8))
        holder=tk.Frame(main,bg=INK);holder.pack(fill='both',expand=True)
        self.page=BubblePage(holder)
    def select(self,key):
        for k,item in self.nav.items():item.configure(bg=PANEL_ALT if k==key else SIDEBAR,fg=ACCENT if k==key else TEXT,image=icon(k,'accent' if k==key else 'text'))
        self.page.set_art(HERE/'neptune-art.jpg')
        self.title.set(dict(self.PAGES).get(key,'Overview'))
        for child in self.actions.winfo_children():child.destroy()


def tool_button(shell,text,command,icon_name=None):
    """A sidebar tool: looks like a page link, runs a command."""
    item=tk.Label(shell.tools,text=('  '+text) if icon_name else text,image=icon(icon_name) if icon_name else '',compound='left',font=('Arial',11),fg=TEXT,bg=SIDEBAR,anchor='w',padx=10,pady=6,cursor='hand2')
    item.pack(fill='x');item.bind('<Button-1>',lambda event:command());return item


def _when(t):
    import time as _time
    return _time.strftime('%b %d, %H:%M',_time.localtime(t)) if isinstance(t,(int,float)) and t>0 else '—'


def score(kind,p):
    """Robot detector: held-out F1. Field detector: targets and corners placed, and how far off."""
    if not isinstance(p,dict):return '—'
    if kind=='robot':return 'F1 %.2f'%p['f1'] if isinstance(p.get('f1'),(int,float)) else '—'
    if not p.get('found') or not isinstance(p.get('mean_error'),(int,float)):return 'none found'
    return '%d/%d · %.1f%% off'%(p['found'],p.get('expected',0),p['mean_error']*100)


def run_result(r):
    state=r.get('state')
    if state=='done':return ('Switched to it',GOOD) if r.get('adopted') else ('Kept the one in use',MUTED)
    return {'failed':('Failed',WARN),'cancelled':('Cancelled',MUTED),'claimed':('Running',ACCENT)}.get(state,('Waiting',MUTED))


def _label(parent,text,size=11,color=TEXT,weight='normal',font='Arial',bg=PANEL):
    tk.Label(parent,text=text,font=(font,size,weight),fg=color,bg=bg,anchor='w',justify='left').pack(fill='x',pady=(0,3))


def tiles(page,specs):
    """A row of equal tiles: (label, value, note, note colour, click handler or None)."""
    row=tk.Frame(page,bg=INK)
    for i,(label,value,note,color,click) in enumerate(specs):
        row.columnconfigure(i,weight=1,uniform='tile')
        outer=tk.Frame(row,bg=LINE,padx=1,pady=1);outer.grid(row=0,column=i,sticky='nsew',padx=(0 if i==0 else 6,0 if i==len(specs)-1 else 6))
        inner=tk.Frame(outer,bg=PANEL,padx=14,pady=12,cursor='hand2' if click else '');inner.pack(fill='both',expand=True)
        for text,size,col,weight in ((label,10,MUTED,'normal'),(value,17,TEXT,'bold'),(note,9,color or MUTED,'normal')):
            w=tk.Label(inner,text=text,font=('Arial',size,weight),fg=col,bg=PANEL,anchor='w');w.pack(fill='x')
            if click:w.bind('<Button-1>',lambda event,c=click:c())
        if click:inner.bind('<Button-1>',lambda event,c=click:c())
    page.add(row);return row


def _clock(seconds):
    s=max(0,round(seconds));return '%d:%02d:%02d'%(s//3600,s//60%60,s%60) if s>=3600 else '%d:%02d'%(s//60,s%60)


def cancel_buttons(parent,run,on_cancel):
    """Cancel for a detector run (request); Stop after this match / Cancel now for an Actions run (job)."""
    if not on_cancel:return
    if run.get('request'):Button(parent,'Cancel',lambda:on_cancel({'request':run['request']}),small=True).pack(side='right',padx=(8,0))
    elif run.get('job'):
        Button(parent,'Cancel now',lambda:on_cancel({'job':run['job'],'mode':'cancel'}),small=True).pack(side='right',padx=(8,0))
        if run.get('state')=='running':Button(parent,'Stop after this match',lambda:on_cancel({'job':run['job'],'mode':'stop'}),small=True).pack(side='right',padx=(8,0))


def console(page,job,worker,*,self_id=None,on_cancel=None):
    """One running job as the website's console: part and helper, step and match, live readings,
    stage and overall bars, next steps and recent log."""
    import re,time
    lines=(job.get('message') or '').splitlines()
    get=lambda prefix:next((l[len(prefix):].strip() for l in lines if l.startswith(prefix)),'')
    now=re.sub(r'^Training (robot|field) detector: ','',get('Now:'))
    step=next((re.sub(r'^.*? · step (\d+ of \d+): ?',r'Step \1 · ',l) for l in lines if ' · step ' in l),None)
    log=[(row[0],str(row[1])) for row in (worker or {}).get('log') or [] if len(row)>1]
    elapsed=time.time()-job['started'] if isinstance(job.get('started'),(int,float)) and job['started']>0 else None
    overall=None;eta=''
    m=re.match(r'(\d+)%(?: · (.*))?',get('Progress:'))
    if m:overall=float(m.group(1));eta=re.sub(r'^about ','~',m.group(2) or '')
    else:
        p=re.search(r'pass (\d+) of up to (\d+)(?: · batch (\d+) of (\d+))?',now)
        if p and int(p.group(2)):
            # Detector runs report passes, not an overall figure; same estimate as the website.
            f=((int(p.group(1))-1)+(int(p.group(3))/max(1,int(p.group(4))) if p.group(3) else 0))/int(p.group(2));overall=round(100*(.1+.82*f))
            if elapsed and elapsed>60 and overall>3:
                left=elapsed/(overall/100)*(1-overall/100);eta='~%d min left'%(left/60) if left<3600 else '~%.1f h left'%(left/3600)
    k=(worker or {}).get('counters') or {};stage=None;stage_text=''
    if k.get('passes'):
        stage=100*((k.get('pass',1)-1)+(k.get('batch',0)/k['batches'] if k.get('batches') else 0))/k['passes']
        stage_text='epoch %s/%s · batch %s/%s'%(k.get('pass','?'),k['passes'],k.get('batch','?'),k.get('batches','?'))+(' · loss %.3f'%k['loss'] if isinstance(k.get('loss'),(int,float)) else '')+(' · %s it/s'%k['it_s'] if k.get('it_s') else '')
    elif k.get('matches'):stage=100*max(0,(k.get('match') or 0)-1)/k['matches'];stage_text='match %s of %s'%(k.get('match',0),k['matches'])
    else:
        found=next((re.search(r'\[(\d+)/(\d+)\]',t) for t in [now]+[l for _,l in reversed(log)] if re.search(r'\[(\d+)/(\d+)\]',t)),None)
        if found and int(found.group(2)):stage=100*(int(found.group(1))-1)/int(found.group(2));stage_text='match %s of %s'%(found.group(1),found.group(2))
    key=r'\b(\d{4}[a-z0-9]+_[a-z0-9]+)\b';match=re.search(key,now) or (re.search(key,log[-1][1]) if log else None)
    stats=(worker or {}).get('stats') or {};gpu=stats.get('gpu') or {}
    gb=lambda mb:('%.1f' if mb<10240 else '%.0f')%(mb/1024)
    sys_line=' · '.join(x for x in ['GPU %d%%'%gpu['util'] if isinstance(gpu.get('util'),(int,float)) else '',
        'VRAM '+gb(gpu['mem_used_mb'])+('/'+gb(gpu['mem_total_mb']) if gpu.get('mem_total_mb') else '')+' GB' if isinstance(gpu.get('mem_used_mb'),(int,float)) else '',
        '%d°C'%gpu['temp_c'] if isinstance(gpu.get('temp_c'),(int,float)) else '','CPU %d%%'%stats['cpu'] if isinstance(stats.get('cpu'),(int,float)) else '',
        'RAM %s/%s GB'%(gb(stats.get('ram_used_mb') or 0),gb(stats['ram_total_mb'])) if stats.get('ram_total_mb') else ''] if x)
    mono='Menlo'
    label=job.get('label') or job.get('title') or 'Training'
    outer,card=page_card(page,'● '+label,key='run:'+label)
    cancel_buttons(outer.head,job,on_cancel)
    helper='waiting for its helper' if not worker else (worker.get('name') or 'Computer')+(' (this computer)' if worker.get('id')==self_id else '')+(' · '+gpu['name'].replace('NVIDIA GeForce ','') if gpu.get('name') else '')
    tk.Label(outer.head,text=helper,font=(mono,10),fg=MUTED,bg=PANEL).pack(side='right')
    _label(card,(step or job.get('title') or '')+(' · '+match.group(1) if match else ''),12,TEXT,'bold',mono)
    _label(card,sys_line or 'no live readings from this helper yet',10,ACCENT,font=mono)
    def bar_row(label,p,detail):
        row=tk.Frame(card,bg=PANEL);row.pack(fill='x',pady=2)
        tk.Label(row,text=label,font=(mono,11),fg=TEXT,bg=PANEL,width=8,anchor='w').pack(side='left')
        track=tk.Frame(row,bg='#0f1d31',width=240,height=8);track.pack(side='left',padx=(0,10));track.pack_propagate(False)
        if p:tk.Frame(track,bg='#8fb6de',width=max(1,int(240*min(100,p)/100)),height=8).place(x=0,y=0)
        tk.Label(row,text='  —' if p is None else '%3d%%'%round(p),font=(mono,11,'bold'),fg=TEXT,bg=PANEL).pack(side='left')
        tk.Label(row,text=detail,font=(mono,11),fg=MUTED,bg=PANEL,anchor='w').pack(side='left',padx=(10,0))
    bar_row('stage',stage,stage_text or now or '—')
    bar_row('overall',overall,' · '.join(x for x in [_clock(elapsed)+' elapsed' if elapsed else '',eta or ('estimating' if overall is not None else '')] if x))
    if get('Next:'):_label(card,'next → '+get('Next:'),10,MUTED,font=mono)
    for t,message in log[-6:]:
        low=message.lower()
        color='#fa8c8c' if re.search('fail|error|could not|refused|timed out',low) else WARN if re.search('warn|skipp|retry|missing',low) else GOOD if re.search('finish|saved|adopted|done|complete|kept|passed',low) else '#8cc7f7'
        _label(card,time.strftime('%H:%M:%S',time.localtime(t))+'  '+message,10,color,font=mono)


def job_kind(job):
    """Which model a queued or running job trains: robots, field or actions."""
    text=(job.get('label') or '')+' '+(job.get('title') or '')
    return 'field' if 'Field' in text or 'field' in text else 'robots' if 'Robot' in text or 'robot' in text or 'frames' in text else 'actions'


def render_overview(page,dash,models,*,self_id=None,busy=False,open_page=lambda k:None,open_url=lambda url:None,on_cancel=None):
    """Running now as the centerpiece; a model's tile only while it trains; a compact Ready-to-train row when idle; the queue when it has runs."""
    computers=dash.get('computers') or [];queue=dash.get('queue') or []
    running=[q for q in queue if q.get('state')=='running']
    for job in running[:3]:
        # Detector runs name their helper in computer_id; action runs in target.
        helper=job.get('computer_id') or job.get('target')
        console(page,job,next((c for c in computers if helper and c.get('id')==helper),None),self_id=self_id,on_cancel=on_cancel)
    active={job_kind(q) for q in running}
    if active:
        robot,field,actions=models.get('robot'),models.get('field'),models.get('actions')
        tagged=((actions or {}).get('counts') or {}).get('matches') or 0
        specs={'robots':('Robots · training',score('robot',(robot.get('current') or {}).get('new')) if robot and robot.get('current') else 'Built-in','in use · %d labelled matches'%(robot.get('matches') or 0) if robot else '',None,lambda:open_page('robots')),
               'field':('Field · training',score('field',(field.get('current') or {}).get('new')) if field and field.get('current') else 'Marked zones','in use · %d events marked'%len(field.get('events') or []) if field else '',None,lambda:open_page('field')),
               'actions':('Actions · training',('Released' if actions.get('released') else 'Not released') if actions else '—','%d tagged matches'%tagged if actions else '',None,lambda:open_page('actions'))}
        tiles(page,[specs[k] for k in ('robots','field','actions') if k in active])
    else:
        _,card=page_card(page,None);row=tk.Frame(card,bg=PANEL);row.pack(fill='x')
        tk.Label(row,text='Nothing running. Start a run:',font=('Arial',10),fg=MUTED,bg=PANEL).pack(side='left',padx=(0,10))
        Button(row,'Robot detector',lambda:open_page('robots'),icon_name='robots',small=True).pack(side='left',padx=(0,6))
        Button(row,'Field detector',lambda:open_page('field'),icon_name='field',small=True).pack(side='left',padx=(0,6))
        Button(row,'Actions on Neptune',lambda:open_url('https://neptunefrc.com/autoscout/train.php#train'),icon_name='open',small=True).pack(side='left')
    waiting=[q for q in queue if q.get('state')=='waiting']
    if waiting:
        _,card=page_card(page,'Queue · %d waiting'%len(waiting),key='queue')
        for item in waiting:
            row=tk.Frame(card,bg=PANEL);row.pack(fill='x',pady=2)
            cancel_buttons(row,item,on_cancel)
            tk.Label(row,text=(item.get('title') or 'Run')+' · '+(item.get('message') or 'Waiting'),font=('Arial',10),fg=TEXT,bg=PANEL,anchor='w').pack(side='left')


def render_model(page,kind,m,*,open_url=lambda url:None,connected=True,on_cancel=None):
    """A detector's page: the model in use, its held-out match, its data and the last runs."""
    if not m:
        _,card=page_card(page,None);_label(card,'Loading this model’s status…' if connected else 'Connect this computer to Neptune to see this model.',10,MUTED);return
    current=m.get('current')
    data=('%d matches'%(m.get('matches') or 0),'%d labelled frames'%(m.get('frames') or 0)) if kind=='robot' else ('%d matches'%(m.get('marked') or 0),', '.join(m.get('events') or []) or '—')
    tiles(page,[('Model in use',score(kind,current.get('new')) if current else 'Built-in','Adopted '+_when(current.get('adopted_at')) if current else 'No retrained model has passed the check yet',None,None),
                ('Checked on',m.get('holdout') or '—','Held out · never trained on',None,None),
                ('Labelled data' if kind=='robot' else 'Marked data',data[0],data[1],None,None)])
    if kind=='field':_,card=page_card(page,None);_label(card,'Scouting uses your marked zones. Mark one match at each event you scout; training this detector is optional.',10,MUTED)
    elif not m.get('ready'):_,card=page_card(page,None);_label(card,'Label robots in at least two matches on Neptune before training.',10,WARN)
    _,card=page_card(page,'Recent runs',key='runs:'+kind)
    runs=m.get('runs') or []
    if not runs:_label(card,'No runs yet.',10,MUTED)
    for i,r in enumerate(runs):
        row=tk.Frame(card,bg=PANEL);row.pack(fill='x')
        result,color=run_result(r)
        tk.Label(row,text=_when(r.get('created_at')),font=('Arial',10),fg=MUTED,bg=PANEL,width=14,anchor='w').pack(side='left')
        tk.Label(row,text=result,font=('Arial',10,'bold'),fg=color,bg=PANEL,width=17,anchor='w').pack(side='left')
        versus='—' if not isinstance(r.get('new'),dict) else score(kind,r['new'])+' vs '+(score(kind,r['current']) if isinstance(r.get('current'),dict) else 'none')
        tk.Label(row,text=versus,font=('Arial',10),fg=TEXT,bg=PANEL,anchor='w').pack(side='left')
        if r.get('state') in ('queued','claimed') and r.get('id'):cancel_buttons(row,{'request':r['id']},on_cancel)
        review=r.get('review') or {}
        if isinstance(review.get('youtube_id'),str) and len(review['youtube_id'])==11:
            watch=tk.Label(row,text='▶ Watch',font=('Arial',10),fg=ACCENT,bg=PANEL,cursor='hand2');watch.pack(side='right')
            watch.bind('<Button-1>',lambda event,u='https://youtu.be/'+review['youtube_id']:open_url(u))
        elif review.get('status')=='upload_failed':tk.Label(row,text='Video upload failed',font=('Arial',9),fg=MUTED,bg=PANEL).pack(side='right')
        if r.get('message'):_label(card,r['message'],9,MUTED)
        if i<len(runs)-1:tk.Frame(card,bg=LINE,height=1).pack(fill='x',pady=6)


def render_actions(page,a,*,connected=True):
    if not a:
        _,card=page_card(page,None);_label(card,'Loading the action model’s status…' if connected else 'Connect this computer to Neptune to see the action model.',10,MUTED);return
    counts=a.get('counts') or {};tagged=counts.get('matches') or 0;latest=a.get('latest')
    tiles(page,[('Model in use','Released' if a.get('released') else 'None released','Latest trained '+_when(latest.get('created_at')) if latest else 'No model trained yet',None,None),
                ('Tagged matches',str(tagged),'Needs tagged matches from more events' if tagged<6 else 'Human-tagged',WARN if tagged<6 else None,None),
                ('Human tags',str(counts.get('tags') or 0),'%d imported actions'%(counts.get('external') or 0),None,None)])
    _,card=page_card(page,'Improve this model',key='improve-actions')
    _label(card,'Tag scoring, climbing and defense in matches from events not tagged yet; more variety helps more than more of one event.',10,MUTED)
    _label(card,'Train on Neptune picks the matches and the computer, then Test & release checks the new model before it’s used.',10,MUTED)


def update_line(w):
    """A computer's helper version against the newest release: text, colour, and which button applies (update, cancel or none)."""
    u=w.get('update') or {};latest=u.get('latest') or ''
    if not u.get('remote'):
        return (('Update %s available · update this helper once by hand to allow remote updates'%latest) if u.get('behind') else 'Remote updates arrive with the next helper update'),MUTED,None
    if u.get('requested'):return u.get('message') or 'Update requested · installs when the helper is idle, then reopens',ACCENT,'cancel'
    if u.get('behind'):return 'Update %s available'%latest,WARN,'request'
    return ('Up to date · '+latest) if latest else 'Up to date',GOOD,None


def render_computers(page,dash,*,self_id=None,on_update=None):
    """Every connected computer with its live readings; this one first. on_update(computer_id, change) requests, cancels or sets auto-update."""
    computers=sorted(dash.get('computers') or [],key=lambda c:c.get('id')!=self_id)
    if not computers:_,card=page_card(page,None);_label(card,'No computer readouts yet.',10,MUTED)
    for w in computers:
        state,color=('Training',ACCENT) if w.get('job') else (('Available',GOOD) if w.get('online') else ('Offline',MUTED))
        outer,card=page_card(page,(w.get('name') or 'Computer')+(' · this computer' if w.get('id')==self_id else ''),key='computer:'+str(w.get('id')),icon_name=FORM_ICON.get(w.get('form'),'computers'))
        tk.Label(outer.head,text=state,font=('Arial',10,'bold'),fg=color,bg=PANEL).pack(side='left',padx=10)
        _label(card,' · '.join(x for x in (w.get('platform'),w.get('device'),w.get('version')) if x),9,MUTED)
        text,color,button=update_line(w);row=tk.Frame(card,bg=PANEL);row.pack(fill='x',pady=(2,0))
        tk.Label(row,text=text,font=('Arial',9),fg=color,bg=PANEL,wraplength=520,justify='left').pack(side='left')
        if on_update and (w.get('update') or {}).get('remote') and w.get('id'):
            cid=w['id']
            if button:Button(row,'Update' if button=='request' else 'Cancel update',lambda c=cid,b=button:on_update(c,{'update':b}),kind='primary' if button=='request' else 'secondary',icon_name='download' if button=='request' else None,small=True).pack(side='left',padx=(10,0))
            Switch(row,'Update automatically',bool((w.get('update') or {}).get('auto')),lambda value,c=cid:on_update(c,{'auto_update':value})).pack(side='left',padx=(12,0))
        stats=w.get('stats') or {};gpu=stats.get('gpu') or {}
        def memory(used,total):return '—' if used is None else '%.1f / %.1f GB'%(used/1024,total/1024) if total else '%.1f GB'%(used/1024)
        grid=tk.Frame(card,bg=PANEL);grid.pack(fill='x',pady=(4,4))
        cells=[('CPU',(stats.get('cpu') or 0)/100,'%g%%'%stats['cpu'] if isinstance(stats.get('cpu'),(int,float)) else '—'),
               ('GPU',(gpu.get('util') or 0)/100,'%g%%'%gpu['util'] if isinstance(gpu.get('util'),(int,float)) else '—'),
               ('RAM',stats['ram_used_mb']/stats['ram_total_mb'] if stats.get('ram_used_mb') is not None and stats.get('ram_total_mb') else None,memory(stats.get('ram_used_mb'),stats.get('ram_total_mb'))),
               ('GPU memory',gpu['mem_used_mb']/gpu['mem_total_mb'] if gpu.get('mem_used_mb') is not None and gpu.get('mem_total_mb') else None,memory(gpu.get('mem_used_mb'),gpu.get('mem_total_mb')))]
        for i,(name,fraction,text) in enumerate(cells):
            grid.columnconfigure(i,weight=1,uniform='m');_meter(grid,name,fraction,text).grid(row=0,column=i,sticky='ew',padx=(0,14) if i<3 else 0)
        _label(card,(w.get('job') or {}).get('message') or 'No assigned job',9,MUTED)
